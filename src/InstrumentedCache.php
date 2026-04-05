<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use yii\redis\Cache;

/**
 * Extends yii\redis\Cache to wrap cache operations with OTEL child spans.
 *
 * Since yii\redis\Cache does not fire events for get/set/delete/flush,
 * this subclass overrides the low-level getValue(), setValue(), deleteValue(),
 * and flushValues() methods to create OTEL spans around each operation.
 *
 * The tracer is set via a static property from OtelBootstrap, following the
 * same pattern as DbInstrumentation.
 *
 * Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 17.2
 */
class InstrumentedCache extends Cache
{
    /**
     * @var TracerInterface|null Shared tracer instance, set from OtelBootstrap.
     */
    private static ?TracerInterface $tracer = null;

    /**
     * Sets the tracer instance used by all InstrumentedCache instances.
     *
     * @param TracerInterface $tracer The OTEL tracer
     */
    public static function setTracer(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;
    }

    /**
     * Returns the registered tracer instance.
     *
     * @return TracerInterface|null
     */
    public static function getTracer(): ?TracerInterface
    {
        return self::$tracer;
    }

    /**
     * @inheritdoc
     *
     * Wraps the parent getValue() with a CACHE GET span.
     * Sets cache.hit to true if the return value is not false.
     */
    protected function getValue($key)
    {
        if (self::$tracer === null) {
            return parent::getValue($key);
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return parent::getValue($key);
        }

        $span = self::$tracer->spanBuilder('CACHE GET')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = parent::getValue($key);
            $span->setAttribute('cache.hit', $result !== false);
            $span->end();
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            throw $e;
        } finally {
            $scope->detach();
        }
    }

    /**
     * @inheritdoc
     *
     * Wraps the parent setValue() with a CACHE SET span.
     */
    protected function setValue($key, $value, $duration)
    {
        if (self::$tracer === null) {
            return parent::setValue($key, $value, $duration);
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return parent::setValue($key, $value, $duration);
        }

        $span = self::$tracer->spanBuilder('CACHE SET')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = parent::setValue($key, $value, $duration);
            $span->end();
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            throw $e;
        } finally {
            $scope->detach();
        }
    }

    /**
     * @inheritdoc
     *
     * Wraps the parent deleteValue() with a CACHE DELETE span.
     */
    protected function deleteValue($key)
    {
        if (self::$tracer === null) {
            return parent::deleteValue($key);
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return parent::deleteValue($key);
        }

        $span = self::$tracer->spanBuilder('CACHE DELETE')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = parent::deleteValue($key);
            $span->end();
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            throw $e;
        } finally {
            $scope->detach();
        }
    }

    /**
     * @inheritdoc
     *
     * Wraps the parent flushValues() with a CACHE FLUSH span.
     * No cache.key attribute is set for flush operations.
     */
    protected function flushValues()
    {
        if (self::$tracer === null) {
            return parent::flushValues();
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return parent::flushValues();
        }

        $span = self::$tracer->spanBuilder('CACHE FLUSH')
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = parent::flushValues();
            $span->end();
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            throw $e;
        } finally {
            $scope->detach();
        }
    }
}
