<?php

namespace common\tests\unit\otel;

use common\components\MultiTenantJob;
use danvick\yii2\otel\QueueInstrumentation;
use danvick\yii2\otel\OtelHelpers;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ScopeInterface;
use yii\queue\ExecEvent;
use yii\queue\PushEvent;
use yii\queue\Queue;

/**
 * A recording span for queue tests that captures attributes, links, exceptions, and events.
 */
class QueueRecordingSpan extends Span
{
    /** @var array<string, mixed> */
    public array $attributes = [];
    public ?string $statusCode = null;
    public ?string $statusDescription = null;
    /** @var \Throwable[] */
    public array $recordedExceptions = [];
    /** @var array{name: string, attributes: array}[] */
    public array $events = [];
    public bool $ended = false;

    private SpanContextInterface $spanContext;

    public function __construct(?SpanContextInterface $spanContext = null)
    {
        $this->spanContext = $spanContext ?? SpanContext::getInvalid();
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
        $this->events[] = ['name' => $name, 'attributes' => iterator_to_array($attributes)];
        return $this;
    }

    public function recordException(\Throwable $exception, iterable $attributes = []): SpanInterface
    {
        $this->recordedExceptions[] = $exception;
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        $this->statusCode = $code;
        $this->statusDescription = $description;
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
        $this->ended = true;
    }
}


/**
 * A recording SpanBuilder for queue tests that captures span name, links, and kind.
 */
class QueueRecordingSpanBuilder implements SpanBuilderInterface
{
    public string $spanName;
    public QueueRecordingSpan $span;
    /** @var array<string, mixed> */
    public array $builderAttributes = [];
    /** @var SpanContextInterface[] Captured SpanLink contexts */
    public array $links = [];
    public ?int $spanKind = null;

    public function __construct(string $spanName, QueueRecordingSpan $span)
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
        $this->links[] = $context;
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
        $this->spanKind = $spanKind;
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
 * A test span used to activate a known trace context during push tests.
 * Extends the abstract Span class with a configurable SpanContext.
 */
class QueueTestSpan extends Span
{
    private SpanContextInterface $spanContext;

    public function __construct(SpanContextInterface $spanContext)
    {
        $this->spanContext = $spanContext;
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
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanInterface
    {
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
    }
}


/**
 * A simple mock job class for testing queue instrumentation.
 * Has a public _otelTraceContext property that QueueInstrumentation reads/writes.
 */
class MockJob extends \yii\base\BaseObject implements \yii\queue\JobInterface
{
    /** @var array|null Trace context injected by QueueInstrumentation */
    public ?array $_otelTraceContext = null;

    public function execute($queue): void
    {
        // no-op
    }
}


/**
 * A mock MultiTenantJob for testing tenant attribute propagation.
 * Extends MultiTenantJob but overrides init() to avoid Yii app dependencies.
 */
class MockMultiTenantJob extends MultiTenantJob implements \yii\queue\JobInterface
{
    /** @var array|null Trace context injected by QueueInstrumentation */
    public ?array $_otelTraceContext = null;

    public function init(): void
    {
        // Skip parent init() which requires Yii::$app and tenant resolution
    }

    public function execute($queue): void
    {
        // no-op
    }
}


/**
 * Property-based and unit tests for QueueInstrumentation.
 *
 * Tests trace context capture on push, SpanLink creation on exec,
 * MultiTenantJob tenant attributes, span naming, and error handling.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5
 */
class QueueInstrumentationTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 100;

    private ?QueueRecordingSpan $recordingSpan = null;
    private ?QueueRecordingSpanBuilder $recordingBuilder = null;
    private ?ScopeInterface $scope = null;

    /**
     * Generate a random non-empty string.
     */
    private function randomString(int $minLen = 3, int $maxLen = 30): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_';
        $len = random_int($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Generate a valid random hex trace ID (32 lowercase hex chars, not all zeros).
     */
    private function randomTraceId(): string
    {
        do {
            $hex = '';
            for ($i = 0; $i < 32; $i++) {
                $hex .= dechex(random_int(0, 15));
            }
        } while ($hex === '00000000000000000000000000000000');
        return $hex;
    }

    /**
     * Generate a valid random hex span ID (16 lowercase hex chars, not all zeros).
     */
    private function randomSpanId(): string
    {
        do {
            $hex = '';
            for ($i = 0; $i < 16; $i++) {
                $hex .= dechex(random_int(0, 15));
            }
        } while ($hex === '0000000000000000');
        return $hex;
    }

    /**
     * Creates a mock TracerInterface that returns a QueueRecordingSpanBuilder → QueueRecordingSpan.
     */
    private function createRecordingTracer(): TracerInterface
    {
        $this->recordingSpan = new QueueRecordingSpan();

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')
            ->willReturnCallback(function (string $name) {
                $this->recordingBuilder = new QueueRecordingSpanBuilder($name, $this->recordingSpan);
                return $this->recordingBuilder;
            });

        return $tracer;
    }

    /**
     * Injects the tracer into QueueInstrumentation's static $tracer property via reflection.
     */
    private function injectTracer(TracerInterface $tracer): void
    {
        $ref = new \ReflectionClass(QueueInstrumentation::class);
        $prop = $ref->getProperty('tracer');
        $prop->setAccessible(true);
        $prop->setValue(null, $tracer);
    }

    /**
     * Resets QueueInstrumentation static state (tracer, activeJobSpan, activeJobScope).
     */
    private function resetQueueInstrumentation(): void
    {
        $ref = new \ReflectionClass(QueueInstrumentation::class);

        $tracer = $ref->getProperty('tracer');
        $tracer->setAccessible(true);
        $tracer->setValue(null, null);

        $span = $ref->getProperty('activeJobSpan');
        $span->setAccessible(true);
        $span->setValue(null, null);

        $scopeProp = $ref->getProperty('activeJobScope');
        $scopeProp->setAccessible(true);
        $scopeVal = $scopeProp->getValue(null);
        if ($scopeVal instanceof ScopeInterface) {
            $scopeVal->detach();
        }
        $scopeProp->setValue(null, null);
    }

    /**
     * Creates a PushEvent with the given job.
     */
    private function createPushEvent($job): PushEvent
    {
        $event = new PushEvent();
        $event->job = $job;
        return $event;
    }

    /**
     * Creates an ExecEvent with the given job and optional error.
     */
    private function createExecEvent($job, ?\Throwable $error = null): ExecEvent
    {
        $event = new ExecEvent();
        $event->job = $job;
        if ($error !== null) {
            $event->error = $error;
        }
        return $event;
    }

    protected function tearDown(): void
    {
        if ($this->scope !== null) {
            $this->scope->detach();
            $this->scope = null;
        }
        $this->resetQueueInstrumentation();
        $this->recordingSpan = null;
        $this->recordingBuilder = null;
        parent::tearDown();
    }

    // =========================================================================
    // Property 12: Queue trace context round-trip (Task 9.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 12: Queue trace context round-trip
     *
     * For any job pushed within an active trace, the serialized `_otelTraceContext`
     * on the job payload shall contain the originating `trace_id` and `span_id`.
     * When that job is later executed, the created job span shall contain a SpanLink
     * whose `trace_id` and `span_id` match the values stored in `_otelTraceContext`.
     *
     * Validates: Requirements 6.1, 6.2
     */
    public function testProperty12QueueTraceContextRoundTrip(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate random trace context
            $traceId = $this->randomTraceId();
            $spanId = $this->randomSpanId();

            // Activate a test span with valid context
            $spanContext = SpanContext::create($traceId, $spanId, TraceFlags::SAMPLED);
            $testSpan = new QueueTestSpan($spanContext);

            if ($this->scope !== null) {
                $this->scope->detach();
            }
            $this->scope = $testSpan->activate();

            // Create a job and push event
            $job = new MockJob();
            $pushEvent = $this->createPushEvent($job);

            // Call handleBeforePush — this should inject _otelTraceContext
            QueueInstrumentation::handleBeforePush($pushEvent);

            // Verify _otelTraceContext was set with correct trace_id and span_id
            $this->assertNotNull(
                $job->_otelTraceContext,
                "Iteration {$i}: _otelTraceContext must be set on job after push"
            );
            $this->assertIsArray($job->_otelTraceContext);
            $this->assertArrayHasKey('trace_id', $job->_otelTraceContext);
            $this->assertArrayHasKey('span_id', $job->_otelTraceContext);
            $this->assertSame(
                $traceId,
                $job->_otelTraceContext['trace_id'],
                "Iteration {$i}: trace_id must match originating span"
            );
            $this->assertSame(
                $spanId,
                $job->_otelTraceContext['span_id'],
                "Iteration {$i}: span_id must match originating span"
            );

            // Detach the push-time scope before exec
            $this->scope->detach();
            $this->scope = null;

            // Now simulate job execution — inject tracer and call handleBeforeExec
            $tracer = $this->createRecordingTracer();
            $this->injectTracer($tracer);

            $execEvent = $this->createExecEvent($job);
            QueueInstrumentation::handleBeforeExec($execEvent);

            // Verify SpanLink was added with matching trace_id and span_id
            $this->assertCount(
                1,
                $this->recordingBuilder->links,
                "Iteration {$i}: exactly one SpanLink should be added"
            );

            $linkContext = $this->recordingBuilder->links[0];
            $this->assertSame(
                $traceId,
                $linkContext->getTraceId(),
                "Iteration {$i}: SpanLink trace_id must match _otelTraceContext"
            );
            $this->assertSame(
                $spanId,
                $linkContext->getSpanId(),
                "Iteration {$i}: SpanLink span_id must match _otelTraceContext"
            );

            // Clean up: end the job span
            QueueInstrumentation::handleAfterExec($execEvent);
            $this->resetQueueInstrumentation();
        }
    }

    // =========================================================================
    // Property 14: MultiTenantJob tenant attributes (Task 9.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 14: MultiTenantJob tenant attributes
     *
     * For any job that extends MultiTenantJob with non-null schoolId and schoolName
     * properties, the job root span shall contain `tenant.id` equal to schoolId
     * and `tenant.name` equal to schoolName.
     *
     * Validates: Requirements 6.4
     */
    public function testProperty14MultiTenantJobTenantAttributes(): void
    {
        $this->markTestSkipped(
            'Tenant attribute propagation was removed from QueueInstrumentation. ' .
            'Tenant context is now injected via SpanAttributeProviderInterface in the consuming app.'
        );
    }

    /** @phpstan-ignore-next-line */
    private function _testProperty14MultiTenantJobTenantAttributesOld(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $schoolId = random_int(1, 99999);
            $schoolName = $this->randomString(5, 40);

            $tracer = $this->createRecordingTracer();
            $this->injectTracer($tracer);

            // Create a MultiTenantJob with random schoolId/schoolName
            $job = new MockMultiTenantJob();
            $job->schoolId = $schoolId;
            $job->schoolName = $schoolName;

            $execEvent = $this->createExecEvent($job);
            QueueInstrumentation::handleBeforeExec($execEvent);

            // Verify tenant.id matches schoolId
            $this->assertArrayHasKey(
                'tenant.id',
                $this->recordingSpan->attributes,
                "Iteration {$i}: span must have tenant.id attribute"
            );
            $this->assertSame(
                $schoolId,
                $this->recordingSpan->attributes['tenant.id'],
                "Iteration {$i}: tenant.id must equal schoolId ({$schoolId})"
            );

            // Verify tenant.name matches schoolName
            $this->assertArrayHasKey(
                'tenant.name',
                $this->recordingSpan->attributes,
                "Iteration {$i}: span must have tenant.name attribute"
            );
            $this->assertSame(
                $schoolName,
                $this->recordingSpan->attributes['tenant.name'],
                "Iteration {$i}: tenant.name must equal schoolName ('{$schoolName}')"
            );

            // Clean up
            QueueInstrumentation::handleAfterExec($execEvent);
            $this->resetQueueInstrumentation();
        }
    }

    // =========================================================================
    // Unit Tests (Task 9.4)
    // =========================================================================

    /**
     * Test: Job without _otelTraceContext creates span without link.
     *
     * When a job is executed without _otelTraceContext (backward compatibility),
     * the span should be created but no SpanLink should be added.
     *
     * Validates: Requirements 6.5
     */
    public function testJobWithoutTraceContextCreatesSpanWithoutLink(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $job = new MockJob();
        // Explicitly no _otelTraceContext
        $this->assertNull($job->_otelTraceContext);

        $execEvent = $this->createExecEvent($job);
        QueueInstrumentation::handleBeforeExec($execEvent);

        // Span should be created
        $this->assertNotNull($this->recordingBuilder, 'SpanBuilder should have been called');
        $this->assertStringStartsWith('JOB ', $this->recordingBuilder->spanName);

        // No SpanLink should be added
        $this->assertCount(
            0,
            $this->recordingBuilder->links,
            'No SpanLink should be added when _otelTraceContext is missing'
        );

        QueueInstrumentation::handleAfterExec($execEvent);
    }

    /**
     * Test: Job span name is `JOB {ShortClassName}`.
     *
     * Verifies that the span name follows the `JOB {className}` pattern
     * using the short class name (without namespace).
     *
     * Validates: Requirements 6.3
     */
    public function testJobSpanNameIsJobShortClassName(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $job = new MockJob();
        $execEvent = $this->createExecEvent($job);
        QueueInstrumentation::handleBeforeExec($execEvent);

        $expectedName = 'JOB MockJob';
        $this->assertSame(
            $expectedName,
            $this->recordingBuilder->spanName,
            "Span name should be 'JOB MockJob', got '{$this->recordingBuilder->spanName}'"
        );

        QueueInstrumentation::handleAfterExec($execEvent);
    }

    /**
     * Test: Error event records exception on span.
     *
     * When EVENT_AFTER_ERROR fires with an error, the exception should be
     * recorded on the span and the status set to ERROR.
     *
     * Validates: Requirements 6.5
     */
    public function testErrorEventRecordsExceptionOnSpan(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $job = new MockJob();
        $execEvent = $this->createExecEvent($job);

        // Start the job span
        QueueInstrumentation::handleBeforeExec($execEvent);

        // Simulate error
        $exception = new \RuntimeException('Job processing failed: timeout');
        $errorEvent = $this->createExecEvent($job, $exception);
        QueueInstrumentation::handleAfterError($errorEvent);

        // Exception must be recorded
        $this->assertCount(
            1,
            $this->recordingSpan->recordedExceptions,
            'Exactly one exception should be recorded on the span'
        );
        $this->assertSame($exception, $this->recordingSpan->recordedExceptions[0]);

        // Status must be ERROR
        $this->assertSame(
            StatusCode::STATUS_ERROR,
            $this->recordingSpan->statusCode,
            'Span status must be ERROR after error event'
        );
        $this->assertSame(
            'Job processing failed: timeout',
            $this->recordingSpan->statusDescription
        );

        // Span must be ended
        $this->assertTrue(
            $this->recordingSpan->ended,
            'Span must be ended after error event'
        );
    }
}
