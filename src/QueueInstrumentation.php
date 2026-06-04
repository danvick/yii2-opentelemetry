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
use yii\caching\CacheInterface;
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
     * Temporary in-process store holding trace context between EVENT_BEFORE_PUSH
     * and EVENT_AFTER_PUSH. Keyed by spl_object_id(). Entries are migrated to the
     * Yii cache on after-push (if cache is available) and then removed here.
     *
     * @var array<int, array{trace_id: string, span_id: string}>
     */
    private static array $contextStore = [];

    /**
     * Cache key prefix for trace context entries stored in the Yii cache component.
     */
    private const CACHE_KEY_PREFIX = 'otel_queue_ctx_';

    /**
     * TTL in seconds for cache entries. Should exceed the maximum expected time
     * a job sits in the queue before being picked up by a worker.
     */
    private const CACHE_TTL = 3600;

    /**
     * Optional cache override for testing. When set, bypasses \Yii::$app->cache.
     */
    private static ?CacheInterface $cacheOverride = null;

    /**
     * Registers all queue event handlers.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;

        Event::on(Queue::class, Queue::EVENT_BEFORE_PUSH, [static::class, 'handleBeforePush']);
        Event::on(Queue::class, Queue::EVENT_AFTER_PUSH, [static::class, 'handleAfterPush']);
        Event::on(Queue::class, Queue::EVENT_BEFORE_EXEC, [static::class, 'handleBeforeExec']);
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, [static::class, 'handleAfterExec']);
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, [static::class, 'handleAfterError']);
    }

    /**
     * Handles EVENT_BEFORE_PUSH: captures current trace context into a temporary store.
     *
     * Only runs if the current span context is valid (i.e., we are inside an active
     * trace). Tries to set _otelTraceContext directly on the job (works for plain
     * objects and jobs that declare the property). Falls back to the in-process
     * $contextStore for Yii2 Component jobs that reject unknown properties.
     *
     * The context is migrated to the Yii cache on EVENT_AFTER_PUSH once the job ID
     * is available, enabling cross-process context propagation.
     *
     * @param PushEvent $event The push event with the job
     */
    public static function handleBeforePush(PushEvent $event): void
    {
        $spanContext = Span::getCurrent()->getContext();

        if (!$spanContext->isValid()) {
            return;
        }

        $context = [
            'trace_id' => $spanContext->getTraceId(),
            'span_id' => $spanContext->getSpanId(),
        ];

        try {
            // Preferred: store on the job so it survives serialization for
            // cross-process queue drivers (e.g. DB, Redis, SQS workers).
            $event->job->_otelTraceContext = $context;
        } catch (\yii\base\UnknownPropertyException $e) {
            // The job class is a Yii2 Component that does not declare
            // $_otelTraceContext. Hold in the temporary in-process store until
            // EVENT_AFTER_PUSH fires and the job ID is assigned.
            self::$contextStore[spl_object_id($event->job)] = $context;
        }
    }

    /**
     * Handles EVENT_AFTER_PUSH: migrates trace context from the temporary store to
     * the Yii cache component, keyed by the assigned job ID.
     *
     * At this point $event->id is set by the queue driver. Storing in a shared cache
     * (Redis, Memcached, DB) makes the context available to console worker processes
     * that cannot access PHP static memory from the web process.
     *
     * No-op if no cache component is configured or no context was stored for this job.
     *
     * @param PushEvent $event The push event with the assigned job ID
     */
    public static function handleAfterPush(PushEvent $event): void
    {
        $objectId = spl_object_id($event->job);

        if (!isset(self::$contextStore[$objectId])) {
            return;
        }

        $context = self::$contextStore[$objectId];
        unset(self::$contextStore[$objectId]);

        $cache = self::getCache();
        if ($cache === null || $event->id === null) {
            return;
        }

        $cache->set(self::CACHE_KEY_PREFIX . $event->id, $context, self::CACHE_TTL);
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

        // Defensive cleanup: if a previous job span was never ended (e.g. worker was
        // interrupted between exec and after-exec/after-error), end it now to prevent
        // the new job span from being incorrectly nested under a stale parent.
        if (self::$activeJobSpan !== null) {
            self::endJobSpan();
        }

        $spanBuilder = self::$tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CONSUMER);

        // Resolve trace context using three sources in priority order:
        // 1. Job property — set during push, survives cross-process serialization
        //    for jobs that declare $_otelTraceContext.
        // 2. Yii cache — set during after-push for Component jobs that rejected the
        //    property; survives cross-process via a shared cache backend.
        // 3. In-process $contextStore — fallback for same-process (sync) drivers.
        $objectId = spl_object_id($job);
        $traceContext = null;

        if (isset($job->_otelTraceContext) && \is_array($job->_otelTraceContext)) {
            $traceContext = $job->_otelTraceContext;
        } elseif ($event->id !== null && ($cache = self::getCache()) !== null) {
            $cached = $cache->get(self::CACHE_KEY_PREFIX . $event->id);
            if (\is_array($cached)) {
                $traceContext = $cached;
            }
        } elseif (isset(self::$contextStore[$objectId])) {
            $traceContext = self::$contextStore[$objectId];
        }

        unset(self::$contextStore[$objectId]);

        // Add SpanLink to originating trace if context is available
        if ($traceContext !== null) {
            $traceId = $traceContext['trace_id'] ?? '';
            $spanId = $traceContext['span_id'] ?? '';

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
     * Handles EVENT_AFTER_EXEC: ends the job span and clears any cached trace context.
     *
     * @param ExecEvent $event The exec event
     */
    public static function handleAfterExec(ExecEvent $event): void
    {
        self::deleteCachedContext($event->id);
        self::endJobSpan();
    }

    /**
     * Handles EVENT_AFTER_ERROR: records exception on the job span, sets ERROR status,
     * clears any cached trace context, and ends the span.
     *
     * @param ExecEvent $event The exec event with the error
     */
    public static function handleAfterError(ExecEvent $event): void
    {
        if (self::$activeJobSpan !== null && isset($event->error) && $event->error instanceof \Throwable) {
            self::$activeJobSpan->recordException($event->error);
            self::$activeJobSpan->setStatus(StatusCode::STATUS_ERROR, $event->error->getMessage());
        }

        self::deleteCachedContext($event->id);
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

    /**
     * Deletes the cached trace context entry for the given job ID, if any.
     *
     * @param string|null $jobId The queue job ID
     */
    private static function deleteCachedContext(?string $jobId): void
    {
        if ($jobId === null) {
            return;
        }

        $cache = self::getCache();
        if ($cache !== null) {
            $cache->delete(self::CACHE_KEY_PREFIX . $jobId);
        }
    }

    /**
     * Returns the Yii cache component if available, or null.
     *
     * Checks $cacheOverride first (for testing), then falls back to \Yii::$app->cache.
     *
     * @return CacheInterface|null
     */
    private static function getCache(): ?CacheInterface
    {
        if (self::$cacheOverride !== null) {
            return self::$cacheOverride;
        }

        if (\Yii::$app !== null && \Yii::$app->has('cache')) {
            return \Yii::$app->cache;
        }

        return null;
    }
}
