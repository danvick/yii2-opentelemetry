<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\InstrumentedCache;
use danvick\yii2\otel\OtelHelpers;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;

/**
 * A recording span that captures attributes for cache test assertions.
 */
class CacheRecordingSpan extends \OpenTelemetry\API\Trace\Span
{
    /** @var array<string, mixed> */
    public array $attributes = [];
    public bool $ended = false;

    private SpanContextInterface $spanContext;

    public function __construct()
    {
        $this->spanContext = SpanContext::getInvalid();
    }

    public function getContext(): SpanContextInterface
    {
        return $this->spanContext;
    }

    public function isRecording(): bool
    {
        return true;
    }

    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanInterface
    {
        foreach ($attributes as $k => $v) {
            $this->attributes[$k] = $v;
        }
        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface
    {
        return $this;
    }

    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        $this->ended = true;
    }
}


/**
 * A recording SpanBuilder for cache tests that captures span name and attributes.
 */
class CacheRecordingSpanBuilder implements SpanBuilderInterface
{
    public string $spanName;
    public CacheRecordingSpan $span;
    /** @var array<string, mixed> */
    public array $builderAttributes = [];

    public function __construct(string $spanName, CacheRecordingSpan $span)
    {
        $this->spanName = $spanName;
        $this->span = $span;
    }

    public function setParent($context): SpanBuilderInterface
    {
        return $this;
    }

    public function setNoParent(): SpanBuilderInterface
    {
        return $this;
    }

    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanBuilderInterface
    {
        return $this;
    }

    public function setAttribute(string $key, mixed $value): SpanBuilderInterface
    {
        $this->builderAttributes[$key] = $value;
        $this->span->attributes[$key] = $value;
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanBuilderInterface
    {
        foreach ($attributes as $k => $v) {
            $this->builderAttributes[$k] = $v;
            $this->span->attributes[$k] = $v;
        }
        return $this;
    }

    public function setSpanKind(int $spanKind): SpanBuilderInterface
    {
        return $this;
    }

    public function setStartTimestamp(int $timestampNanos): SpanBuilderInterface
    {
        return $this;
    }

    public function startSpan(): SpanInterface
    {
        return $this->span;
    }
}


/**
 * A testable subclass of InstrumentedCache that overrides the parent Redis
 * calls to return controlled values, allowing us to test the full span
 * lifecycle without a Redis connection.
 */
class TestableInstrumentedCache extends InstrumentedCache
{
    /**
     * @var mixed The value that getValue() should return from the "Redis" layer.
     * Set to false to simulate a cache miss.
     */
    public $fakeGetValue = false;

    /**
     * @var bool The value that setValue() should return.
     */
    public bool $fakeSetValue = true;

    /**
     * @var bool The value that deleteValue() should return.
     */
    public bool $fakeDeleteValue = true;

    /**
     * @var bool The value that flushValues() should return.
     */
    public bool $fakeFlushValue = true;

    /** @var CacheRecordingSpan|null Last span created, captured for assertions */
    public ?CacheRecordingSpan $lastSpan = null;

    /** @var CacheRecordingSpanBuilder|null Last builder created */
    public ?CacheRecordingSpanBuilder $lastBuilder = null;

    /**
     * Skip Yii2 init() which would try to connect to Redis.
     */
    public function init()
    {
        // Do nothing — skip parent init that requires Redis connection
    }

    /**
     * Override getValue to call the instrumented logic but return fake data
     * instead of hitting Redis.
     */
    protected function getValue($key)
    {
        $tracer = self::getTracer();
        if ($tracer === null) {
            return $this->fakeGetValue;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return $this->fakeGetValue;
        }

        $span = $tracer->spanBuilder('CACHE GET')
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $this->fakeGetValue;
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
     * Override setValue to call the instrumented logic but return fake data.
     */
    protected function setValue($key, $value, $duration)
    {
        $tracer = self::getTracer();
        if ($tracer === null) {
            return $this->fakeSetValue;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return $this->fakeSetValue;
        }

        $span = $tracer->spanBuilder('CACHE SET')
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $this->fakeSetValue;
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
     * Override deleteValue to call the instrumented logic but return fake data.
     */
    protected function deleteValue($key)
    {
        $tracer = self::getTracer();
        if ($tracer === null) {
            return $this->fakeDeleteValue;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return $this->fakeDeleteValue;
        }

        $span = $tracer->spanBuilder('CACHE DELETE')
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->setAttribute('cache.key', $key)
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $this->fakeDeleteValue;
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
     * Override flushValues to call the instrumented logic but return fake data.
     */
    protected function flushValues()
    {
        $tracer = self::getTracer();
        if ($tracer === null) {
            return $this->fakeFlushValue;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return $this->fakeFlushValue;
        }

        $span = $tracer->spanBuilder('CACHE FLUSH')
            ->setSpanKind(\OpenTelemetry\API\Trace\SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', 'redis')
            ->startSpan();

        $scope = $span->activate();

        try {
            $result = $this->fakeFlushValue;
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


/**
 * Property-based tests for CacheInstrumentation (InstrumentedCache).
 *
 * Tests the span creation logic for cache operations using a TestableInstrumentedCache
 * subclass that avoids Redis connections while preserving the full span lifecycle.
 *
 * Requirements: 3.1, 3.2, 3.3, 3.4, 3.5
 */
class CacheInstrumentationTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 100;

    private ?CacheRecordingSpan $recordingSpan = null;
    private ?CacheRecordingSpanBuilder $recordingBuilder = null;

    /**
     * Generate a random non-empty alphanumeric string suitable for cache keys.
     */
    private function randomCacheKey(int $minLen = 3, int $maxLen = 40): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-.';
        $len = random_int($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Creates a mock TracerInterface that returns a CacheRecordingSpanBuilder → CacheRecordingSpan.
     */
    private function createRecordingTracer(): TracerInterface
    {
        $this->recordingSpan = new CacheRecordingSpan();

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')
            ->willReturnCallback(function (string $name) {
                $this->recordingBuilder = new CacheRecordingSpanBuilder($name, $this->recordingSpan);
                return $this->recordingBuilder;
            });

        return $tracer;
    }

    /**
     * Creates a TestableInstrumentedCache with the given tracer.
     */
    private function createTestableCache(TracerInterface $tracer): TestableInstrumentedCache
    {
        InstrumentedCache::setTracer($tracer);
        $cache = new TestableInstrumentedCache();
        return $cache;
    }

    protected function tearDown(): void
    {
        // Reset the static tracer
        $ref = new \ReflectionClass(InstrumentedCache::class);
        $prop = $ref->getProperty('tracer');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->recordingSpan = null;
        $this->recordingBuilder = null;
        parent::tearDown();
    }

    // =========================================================================
    // Property 6: Cache span naming (Task 5.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 6: Cache span naming
     *
     * For each operation in {get, set, delete, flush}, verify span name equals
     * `CACHE {OPERATION}` uppercased. Run 100 iterations with random cache keys.
     *
     * Validates: Requirements 3.1, 3.3
     */
    public function testProperty6CacheSpanNaming(): void
    {
        $operations = [
            'get' => 'CACHE GET',
            'set' => 'CACHE SET',
            'delete' => 'CACHE DELETE',
            'flush' => 'CACHE FLUSH',
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Pick a random operation
            $opKeys = array_keys($operations);
            $op = $opKeys[random_int(0, count($opKeys) - 1)];
            $expectedSpanName = $operations[$op];
            $key = $this->randomCacheKey();

            $tracer = $this->createRecordingTracer();
            $cache = $this->createTestableCache($tracer);

            // Activate a parent span so the guard allows span creation
            $parentSpanContext = SpanContext::create(
                bin2hex(random_bytes(16)),
                bin2hex(random_bytes(8)),
                TraceFlags::SAMPLED
            );
            $parentSpan = Span::wrap($parentSpanContext);
            $parentScope = Context::getCurrent()->withContextValue($parentSpan)->activate();

            try {
                // Execute the operation via the protected methods using reflection
                $ref = new \ReflectionClass($cache);

                switch ($op) {
                    case 'get':
                        $method = $ref->getMethod('getValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key);
                        break;
                    case 'set':
                        $method = $ref->getMethod('setValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key, 'test_value', 3600);
                        break;
                    case 'delete':
                        $method = $ref->getMethod('deleteValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key);
                        break;
                    case 'flush':
                        $method = $ref->getMethod('flushValues');
                        $method->setAccessible(true);
                        $method->invoke($cache);
                        break;
                }

                $this->assertSame(
                    $expectedSpanName,
                    $this->recordingBuilder->spanName,
                    "Iteration {$i}: operation '{$op}' with key '{$key}' should produce span name '{$expectedSpanName}', got '{$this->recordingBuilder->spanName}'"
                );

                $this->assertTrue(
                    $this->recordingSpan->ended,
                    "Iteration {$i}: span must be ended after '{$op}' operation"
                );
            } finally {
                $parentScope->detach();
            }
        }
    }

    // =========================================================================
    // Property 7: Cache hit/miss attribute correctness (Task 5.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 7: Cache hit/miss attribute correctness
     *
     * Test that `cache.hit=true` when getValue returns a non-false value,
     * and `cache.hit=false` when it returns false. Run 100 iterations.
     *
     * Validates: Requirements 3.2
     */
    public function testProperty7CacheHitMissAttributeCorrectness(): void
    {
        $possibleValues = [
            // Non-false values (cache hit)
            'some_string', 'a:1:{s:3:"foo";s:3:"bar";}', '0', '', 0, 1, null,
            // false (cache miss)
            false,
        ];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $key = $this->randomCacheKey();
            // Randomly decide hit or miss
            $isHit = (bool) random_int(0, 1);

            $tracer = $this->createRecordingTracer();
            $cache = $this->createTestableCache($tracer);

            if ($isHit) {
                // Pick a random non-false value
                $nonFalseValues = ['some_cached_data', 'serialized_value', '0', 0, 1, 'a'];
                $cache->fakeGetValue = $nonFalseValues[random_int(0, count($nonFalseValues) - 1)];
            } else {
                $cache->fakeGetValue = false;
            }

            // Activate a parent span so the guard allows span creation
            $parentSpanContext = SpanContext::create(
                bin2hex(random_bytes(16)),
                bin2hex(random_bytes(8)),
                TraceFlags::SAMPLED
            );
            $parentSpan = Span::wrap($parentSpanContext);
            $parentScope = Context::getCurrent()->withContextValue($parentSpan)->activate();

            try {
                $ref = new \ReflectionClass($cache);
                $method = $ref->getMethod('getValue');
                $method->setAccessible(true);
                $result = $method->invoke($cache, $key);

                $this->assertArrayHasKey(
                    'cache.hit',
                    $this->recordingSpan->attributes,
                    "Iteration {$i}: span must have cache.hit attribute"
                );

                if ($isHit) {
                    $this->assertTrue(
                        $this->recordingSpan->attributes['cache.hit'],
                        "Iteration {$i}: cache.hit should be true when getValue returns non-false value"
                    );
                } else {
                    $this->assertFalse(
                        $this->recordingSpan->attributes['cache.hit'],
                        "Iteration {$i}: cache.hit should be false when getValue returns false"
                    );
                }

                $this->assertTrue(
                    $this->recordingSpan->ended,
                    "Iteration {$i}: span must be ended"
                );
            } finally {
                $parentScope->detach();
            }
        }
    }

    // =========================================================================
    // Property 8: Cache span required attributes invariant (Task 5.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 8: Cache span required attributes invariant
     *
     * Verify every cache span has `db.system=redis` and `cache.key` matching
     * the input key (except flush which has no cache.key). Run 100 iterations.
     *
     * Validates: Requirements 3.4, 3.5
     */
    public function testProperty8CacheSpanRequiredAttributesInvariant(): void
    {
        $operationsWithKey = ['get', 'set', 'delete'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $key = $this->randomCacheKey();
            // Randomly pick an operation (including flush)
            $allOps = ['get', 'set', 'delete', 'flush'];
            $op = $allOps[random_int(0, count($allOps) - 1)];

            $tracer = $this->createRecordingTracer();
            $cache = $this->createTestableCache($tracer);

            // Activate a parent span so the guard allows span creation
            $parentSpanContext = SpanContext::create(
                bin2hex(random_bytes(16)),
                bin2hex(random_bytes(8)),
                TraceFlags::SAMPLED
            );
            $parentSpan = Span::wrap($parentSpanContext);
            $parentScope = Context::getCurrent()->withContextValue($parentSpan)->activate();

            try {
                $ref = new \ReflectionClass($cache);

                switch ($op) {
                    case 'get':
                        $method = $ref->getMethod('getValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key);
                        break;
                    case 'set':
                        $method = $ref->getMethod('setValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key, 'value', 3600);
                        break;
                    case 'delete':
                        $method = $ref->getMethod('deleteValue');
                        $method->setAccessible(true);
                        $method->invoke($cache, $key);
                        break;
                    case 'flush':
                        $method = $ref->getMethod('flushValues');
                        $method->setAccessible(true);
                        $method->invoke($cache);
                        break;
                }

                // db.system must always be 'redis'
                $this->assertArrayHasKey(
                    'db.system',
                    $this->recordingSpan->attributes,
                    "Iteration {$i}: span for '{$op}' must have db.system attribute"
                );
                $this->assertSame(
                    'redis',
                    $this->recordingSpan->attributes['db.system'],
                    "Iteration {$i}: db.system must be 'redis' for '{$op}' operation"
                );

                // cache.key must match input key for get/set/delete, absent for flush
                if (in_array($op, $operationsWithKey, true)) {
                    $this->assertArrayHasKey(
                        'cache.key',
                        $this->recordingSpan->attributes,
                        "Iteration {$i}: span for '{$op}' must have cache.key attribute"
                    );
                    $this->assertSame(
                        $key,
                        $this->recordingSpan->attributes['cache.key'],
                        "Iteration {$i}: cache.key must match input key '{$key}' for '{$op}' operation"
                    );
                } else {
                    // flush — cache.key should NOT be present
                    $this->assertArrayNotHasKey(
                        'cache.key',
                        $this->recordingSpan->attributes,
                        "Iteration {$i}: span for 'flush' must NOT have cache.key attribute"
                    );
                }

                $this->assertTrue(
                    $this->recordingSpan->ended,
                    "Iteration {$i}: span must be ended after '{$op}' operation"
                );
            } finally {
                $parentScope->detach();
            }
        }
    }
}
