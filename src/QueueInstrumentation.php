<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use yii\base\Event;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * Creates spans for queue job push and execution with trace context linking.
 *
 * - EVENT_BEFORE_PUSH: captures current trace_id + span_id into the job's _otelTraceContext
 * - EVENT_BEFORE_EXEC: creates a new root span `JOB {shortClassName}` with optional SpanLink
 * - EVENT_AFTER_EXEC: ends the job span
 * - EVENT_AFTER_ERROR: records exception, sets ERROR status, ends the job span
 *
 * Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7
 */
class QueueInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /** @var SpanInterface|null The active job span for the currently executing job */
    private static ?SpanInterface $activeJobSpan = null;

    /** @var ScopeInterface|null The scope for the active job span */
    private static ?ScopeInterface $activeJobScope = null;

    /**
     * Registers all queue event handlers.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;

        Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, [static::class, 'handleBeforePush']);
        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, [static::class, 'handleBeforeExec']);
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, [static::class, 'handleAfterExec']);
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, [static::class, 'handleAfterError']);
    }

    /**
     * Handles EVENT_BEFORE_PUSH: captures current trace context into the job payload.
     *
     * Only sets _otelTraceContext if the current span context is valid (i.e., we are
     * inside an active trace).
     *
     * @param PushEvent $event The push event with the job
     */
    public static function handleBeforePush(PushEvent $event): void
    {
        $spanContext = Span::getCurrent()->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $event->job->_otelTraceContext = [
            'trace_id' => $spanContext->getTraceId(),
            'span_id' => $spanContext->getSpanId(),
        ];
    }

    /**
     * Handles EVENT_BEFORE_EXEC: creates a new root span for the job.
     *
     * If _otelTraceContext is present on the job, adds a SpanLink to the originating
     * trace context. MultiTenantJob-specific tenant attribute logic has been removed;
     * tenant attributes are now handled by SpanAttributeProviderInterface in the
     * consuming application.
     *
     * @param ExecEvent $event The exec event with the job
     */
    public static function handleBeforeExec(ExecEvent $event): void
    {
        if (self::$tracer === null) {
            return;
        }

        $job = $event->job;
        $shortName = OtelHelpers::shortClassName(\get_class($job));
        $spanName = "JOB {$shortName}";

        $spanBuilder = self::$tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CONSUMER);

        // Add SpanLink to originating trace if context is available
        if (isset($job->_otelTraceContext) && \is_array($job->_otelTraceContext)) {
            $traceId = $job->_otelTraceContext['trace_id'] ?? '';
            $spanId = $job->_otelTraceContext['span_id'] ?? '';

            if ($traceId !== '' && $spanId !== '') {
                $remoteContext = SpanContext::createFromRemoteParent(
                    $traceId,
                    $spanId,
                    TraceFlags::SAMPLED
                );
                $spanBuilder->addLink($remoteContext);
            }
        }

        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        self::$activeJobSpan = $span;
        self::$activeJobScope = $scope;
    }

    /**
     * Handles EVENT_AFTER_EXEC: ends the job span normally.
     *
     * @param ExecEvent $event The exec event
     */
    public static function handleAfterExec(ExecEvent $event): void
    {
        self::endJobSpan();
    }

    /**
     * Handles EVENT_AFTER_ERROR: records exception on the job span, sets ERROR status, ends span.
     *
     * @param ExecEvent $event The exec event with the error
     */
    public static function handleAfterError(ExecEvent $event): void
    {
        if (self::$activeJobSpan !== null && isset($event->error) && $event->error instanceof \Throwable) {
            self::$activeJobSpan->recordException($event->error);
            self::$activeJobSpan->setStatus(StatusCode::STATUS_ERROR, $event->error->getMessage());
        }

        self::endJobSpan();
    }

    /**
     * Ends the active job span and detaches its scope.
     */
    private static function endJobSpan(): void
    {
        if (self::$activeJobSpan !== null) {
            self::$activeJobSpan->end();
            self::$activeJobSpan = null;
        }

        if (self::$activeJobScope !== null) {
            self::$activeJobScope->detach();
            self::$activeJobScope = null;
        }
    }

    /**
     * Returns the active job span (for testing purposes).
     *
     * @return SpanInterface|null
     */
    public static function getActiveJobSpan(): ?SpanInterface
    {
        return self::$activeJobSpan;
    }
}
