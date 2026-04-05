<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use SplStack;
use yii\base\Event;
use yii\base\ModelEvent;
use yii\db\ActiveRecord;

/**
 * Creates child spans for ActiveRecord save and delete operations.
 *
 * Hooks into ActiveRecord before/after event pairs:
 * - EVENT_BEFORE_INSERT / EVENT_AFTER_INSERT (save, new record)
 * - EVENT_BEFORE_UPDATE / EVENT_AFTER_UPDATE (save, existing record)
 * - EVENT_BEFORE_DELETE / EVENT_AFTER_DELETE
 *
 * Uses a stack to handle nested AR operations (e.g., saving a parent model
 * that triggers child model saves in afterSave()).
 *
 * DB query spans automatically nest under AR spans via OTEL context propagation.
 *
 * Note: find operations are NOT instrumented separately — the DB SELECT spans
 * already capture the query. ActiveRecord::EVENT_AFTER_FIND fires per-row after
 * hydration and has no matching "before" event to wrap the full query.
 *
 * Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 19.8, 19.9, 19.10
 */
class ArInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /**
     * @var SplStack<array{span: SpanInterface, scope: ScopeInterface}>|null
     * Stack of active AR spans for handling nested operations.
     */
    private static ?SplStack $spanStack = null;

    /**
     * Registers ActiveRecord instrumentation by hooking into AR events.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;
        self::$spanStack = new SplStack();

        // Save: insert
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_BEFORE_INSERT, [static::class, 'handleBeforeInsert']);
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_AFTER_INSERT, [static::class, 'handleAfterSave']);

        // Save: update
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_BEFORE_UPDATE, [static::class, 'handleBeforeUpdate']);
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_AFTER_UPDATE, [static::class, 'handleAfterSave']);

        // Delete
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_BEFORE_DELETE, [static::class, 'handleBeforeDelete']);
        Event::on(ActiveRecord::class, ActiveRecord::EVENT_AFTER_DELETE, [static::class, 'handleAfterDelete']);
    }

    /**
     * Handles EVENT_BEFORE_INSERT: creates an AR SAVE span with ar.is_new=true.
     *
     * @param ModelEvent $event The model event
     */
    public static function handleBeforeInsert(ModelEvent $event): void
    {
        self::startSaveSpan($event, true);
    }

    /**
     * Handles EVENT_BEFORE_UPDATE: creates an AR SAVE span with ar.is_new=false.
     *
     * @param ModelEvent $event The model event
     */
    public static function handleBeforeUpdate(ModelEvent $event): void
    {
        self::startSaveSpan($event, false);
    }

    /**
     * Handles EVENT_AFTER_INSERT and EVENT_AFTER_UPDATE: ends the save span.
     *
     * @param Event $event The after-save event
     */
    public static function handleAfterSave(Event $event): void
    {
        self::endTopSpan();
    }

    /**
     * Handles EVENT_BEFORE_DELETE: creates an AR DELETE span.
     *
     * @param ModelEvent $event The model event
     */
    public static function handleBeforeDelete(ModelEvent $event): void
    {
        if (self::$tracer === null || self::$spanStack === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        /** @var ActiveRecord $model */
        $model = $event->sender;
        $shortName = OtelHelpers::shortClassName(\get_class($model));
        $spanName = "AR DELETE {$shortName}";

        try {
            $span = self::$tracer->spanBuilder($spanName)
                ->setAttribute('ar.model', $shortName)
                ->setAttribute('ar.operation', 'delete')
                ->startSpan();

            $scope = $span->activate();

            self::$spanStack->push(['span' => $span, 'scope' => $scope]);
        } catch (\Throwable $e) {
            // Silently fail — don't break AR operations
        }
    }

    /**
     * Handles EVENT_AFTER_DELETE: ends the delete span.
     *
     * @param Event $event The after-delete event
     */
    public static function handleAfterDelete(Event $event): void
    {
        self::endTopSpan();
    }

    /**
     * Creates an AR SAVE span and pushes it onto the stack.
     *
     * @param ModelEvent $event The model event
     * @param bool $isNew Whether this is an insert (true) or update (false)
     */
    private static function startSaveSpan(ModelEvent $event, bool $isNew): void
    {
        if (self::$tracer === null || self::$spanStack === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        /** @var ActiveRecord $model */
        $model = $event->sender;
        $shortName = OtelHelpers::shortClassName(\get_class($model));
        $spanName = "AR SAVE {$shortName}";

        try {
            $span = self::$tracer->spanBuilder($spanName)
                ->setAttribute('ar.model', $shortName)
                ->setAttribute('ar.operation', 'save')
                ->setAttribute('ar.is_new', $isNew)
                ->startSpan();

            $scope = $span->activate();

            self::$spanStack->push(['span' => $span, 'scope' => $scope]);
        } catch (\Throwable $e) {
            // Silently fail — don't break AR operations
        }
    }

    /**
     * Pops the top span from the stack and ends it.
     */
    private static function endTopSpan(): void
    {
        if (self::$spanStack === null || self::$spanStack->isEmpty()) {
            return;
        }

        try {
            $entry = self::$spanStack->pop();
            $entry['span']->end();
            $entry['scope']->detach();
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Cleans up an AR span from the stack when an exception occurs.
     *
     * @param \Throwable $exception The exception that occurred
     */
    public static function handleException(\Throwable $exception): void
    {
        if (self::$spanStack === null || self::$spanStack->isEmpty()) {
            return;
        }

        try {
            $entry = self::$spanStack->pop();
            $entry['span']->recordException($exception);
            $entry['span']->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $entry['span']->end();
            $entry['scope']->detach();
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Returns the span stack (for testing purposes).
     *
     * @return SplStack|null
     */
    public static function getSpanStack(): ?SplStack
    {
        return self::$spanStack;
    }
}
