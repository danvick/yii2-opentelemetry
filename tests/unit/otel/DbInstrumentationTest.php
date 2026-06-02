<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\DbInstrumentation;
use danvick\yii2\otel\OtelHelpers;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanBuilderInterface;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use yii\db\Connection;

/**
 * A recording span that captures attributes, exceptions, and status for assertions.
 */
class RecordingSpan extends \OpenTelemetry\API\Trace\Span
{
    /** @var array<string, mixed> */
    public array $attributes = [];
    public ?string $updatedName = null;
    public ?string $statusCode = null;
    public ?string $statusDescription = null;
    /** @var \Throwable[] */
    public array $recordedExceptions = [];
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
        $this->recordedExceptions[] = $exception;
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        $this->updatedName = $name;
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
 * A recording SpanBuilder that captures the span name and attributes set via
 * the builder, then delegates to a RecordingSpan.
 */
class RecordingSpanBuilder implements SpanBuilderInterface
{
    public string $spanName;
    public RecordingSpan $span;
    /** @var array<string, mixed> */
    public array $builderAttributes = [];

    public function __construct(string $spanName, RecordingSpan $span)
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
        // Also set on the span so wrapWithSpan's attributes are captured
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
 * Property-based and unit tests for DbInstrumentation.
 *
 * Tests the wrapWithSpan() static method directly with mocked tracer, span builder,
 * and connection objects.
 *
 * Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7
 */
class DbInstrumentationTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 100;

    private ?RecordingSpan $recordingSpan = null;
    private ?RecordingSpanBuilder $recordingBuilder = null;
    private ?ScopeInterface $rootScope = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Activate a valid span so hasActiveSpan() returns true inside wrapWithSpan()
        $spanContext = \OpenTelemetry\API\Trace\SpanContext::create(
            bin2hex(random_bytes(16)),
            bin2hex(random_bytes(8)),
            \OpenTelemetry\API\Trace\TraceFlags::SAMPLED
        );
        $rootSpan = \OpenTelemetry\API\Trace\Span::wrap($spanContext);
        $this->rootScope = \OpenTelemetry\Context\Context::getCurrent()
            ->withContextValue($rootSpan)
            ->activate();
    }

    /**
     * Generate a random non-empty alphanumeric string.
     */
    private function randomString(int $minLen = 1, int $maxLen = 20): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = random_int($minLen, $maxLen);
        $str = '';
        for ($i = 0; $i < $len; $i++) {
            $str .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $str;
    }

    /**
     * Creates a mock TracerInterface that returns a RecordingSpanBuilder → RecordingSpan.
     * Captures the span name passed to spanBuilder().
     *
     * @return TracerInterface
     */
    private function createRecordingTracer(): TracerInterface
    {
        $this->recordingSpan = new RecordingSpan();

        $tracer = $this->createMock(TracerInterface::class);
        $tracer->method('spanBuilder')
            ->willReturnCallback(function (string $name) {
                $this->recordingBuilder = new RecordingSpanBuilder($name, $this->recordingSpan);
                return $this->recordingBuilder;
            });

        return $tracer;
    }

    /**
     * Creates a mock yii\db\Connection with the given DSN and component name.
     *
     * @param string $dsn The DSN string
     * @param string $componentName The Yii app component name ('db', 'masterDb', 'statsDb')
     * @return Connection
     */
    private function createMockConnection(string $dsn, string $componentName): Connection
    {
        $connection = $this->getMockBuilder(Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getDriverName'])
            ->getMock();
        $connection->dsn = $dsn;
        $connection->method('getDriverName')->willReturn('mysql');

        // Set up Yii::$app to resolve the connection name via identity check
        $app = $this->createMock(\yii\web\Application::class);
        $app->method('has')
            ->willReturnCallback(fn(string $name) => $name === $componentName);
        $app->method('get')
            ->willReturnCallback(fn(string $name) => $name === $componentName ? $connection : null);
        \Yii::$app = $app;

        // Tell DbInstrumentation which connection names to check
        DbInstrumentation::setKnownConnections([$componentName]);

        return $connection;
    }

    /**
     * Injects the tracer into DbInstrumentation via reflection so wrapWithSpan() uses it.
     */
    private function injectTracer(TracerInterface $tracer): void
    {
        $ref = new \ReflectionClass(DbInstrumentation::class);
        $prop = $ref->getProperty('tracer');
        $prop->setAccessible(true);
        $prop->setValue(null, $tracer);
    }

    protected function tearDown(): void
    {
        $this->rootScope?->detach();
        $this->rootScope = null;

        // Reset the static tracer to null
        $ref = new \ReflectionClass(DbInstrumentation::class);
        $prop = $ref->getProperty('tracer');
        $prop->setAccessible(true);
        $prop->setValue(null, null);

        $this->recordingSpan = null;
        $this->recordingBuilder = null;
        parent::tearDown();
    }

    // =========================================================================
    // Property 5: DB span required attributes invariant (Task 4.3)
    // =========================================================================

    /**
     * Feature: otel-deep-instrumentation, Property 5: DB span required attributes invariant
     *
     * For any DB child span created by the instrumentation layer, the span shall
     * contain `db.system` set to `mysql`, `db.name` set to a non-empty string,
     * and `db.connection_name` set to one of the known connection component IDs
     * (`db`, `masterDb`, `statsDb`).
     *
     * Validates: Requirements 2.1, 2.2, 2.4
     */
    public function testProperty5DbSpanRequiredAttributesInvariant(): void
    {
        $connectionNames = ['db', 'masterDb', 'statsDb'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $tracer = $this->createRecordingTracer();
            $this->injectTracer($tracer);

            $dbName = $this->randomString(3, 30);
            $connName = $connectionNames[random_int(0, count($connectionNames) - 1)];
            $dsn = "mysql:host=localhost;port=3306;dbname={$dbName}";

            $connection = $this->createMockConnection($dsn, $connName);

            $sql = 'SELECT * FROM users WHERE id = :id';
            $params = [':id' => random_int(1, 9999)];

            $result = DbInstrumentation::wrapWithSpan($sql, $params, $connection, fn() => 'ok');

            $this->assertSame('ok', $result, "Iteration {$i}: callback result should be returned");

            // db.system must be 'mysql'
            $this->assertArrayHasKey('db.system', $this->recordingSpan->attributes, "Iteration {$i}: span must have db.system");
            $this->assertSame('mysql', $this->recordingSpan->attributes['db.system'], "Iteration {$i}: db.system must be 'mysql'");

            // db.name must be non-empty and match the generated dbName
            $this->assertArrayHasKey('db.name', $this->recordingSpan->attributes, "Iteration {$i}: span must have db.name");
            $this->assertNotEmpty($this->recordingSpan->attributes['db.name'], "Iteration {$i}: db.name must be non-empty");
            $this->assertSame($dbName, $this->recordingSpan->attributes['db.name'], "Iteration {$i}: db.name must match DSN dbname");

            // db.connection_name must be one of the known names
            $this->assertArrayHasKey('db.connection_name', $this->recordingSpan->attributes, "Iteration {$i}: span must have db.connection_name");
            $this->assertContains(
                $this->recordingSpan->attributes['db.connection_name'],
                $connectionNames,
                "Iteration {$i}: db.connection_name must be one of db, masterDb, statsDb"
            );

            // Span must have been ended
            $this->assertTrue($this->recordingSpan->ended, "Iteration {$i}: span must be ended");
        }
    }

    // =========================================================================
    // Unit Tests (Task 4.4)
    // =========================================================================

    /**
     * Test: Span name is `DB SELECT` for a SELECT query.
     *
     * Validates: Requirements 2.1, 2.7
     */
    public function testSpanNameIsDbSelectForSelectQuery(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $dsn = 'mysql:host=localhost;port=3306;dbname=school_tenant_1';
        $connection = $this->createMockConnection($dsn, 'db');

        $sql = 'SELECT * FROM students WHERE class_id = :classId';
        $params = [':classId' => 5];

        DbInstrumentation::wrapWithSpan($sql, $params, $connection, fn() => []);

        $this->assertSame('DB SELECT', $this->recordingBuilder->spanName);
    }

    /**
     * Test: `db.statement` has params replaced with `?`.
     *
     * Validates: Requirements 2.5
     */
    public function testDbStatementHasParamsReplacedWithPlaceholders(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $dsn = 'mysql:host=localhost;port=3306;dbname=school_tenant_1';
        $connection = $this->createMockConnection($dsn, 'db');

        $sql = 'SELECT * FROM students WHERE name = :name AND class_id = :classId';
        $params = [':name' => 'John Doe', ':classId' => 42];

        DbInstrumentation::wrapWithSpan($sql, $params, $connection, fn() => []);

        $statement = $this->recordingSpan->attributes['db.statement'];
        $this->assertStringNotContainsString(':name', $statement);
        $this->assertStringNotContainsString(':classId', $statement);
        $this->assertSame(2, substr_count($statement, '?'), 'Should have exactly 2 ? placeholders');
        $this->assertStringContainsString('SELECT * FROM students WHERE name = ? AND class_id = ?', $statement);
    }

    /**
     * Test: Exception is recorded on span when query fails.
     *
     * Validates: Requirements 2.6
     */
    public function testExceptionIsRecordedOnSpanWhenQueryFails(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $dsn = 'mysql:host=localhost;port=3306;dbname=school_tenant_1';
        $connection = $this->createMockConnection($dsn, 'db');

        $sql = 'INSERT INTO students (name) VALUES (:name)';
        $params = [':name' => 'Test Student'];
        $expectedException = new \RuntimeException('SQLSTATE[23000]: Integrity constraint violation');

        $caughtException = null;
        try {
            DbInstrumentation::wrapWithSpan($sql, $params, $connection, function () use ($expectedException) {
                throw $expectedException;
            });
        } catch (\RuntimeException $e) {
            $caughtException = $e;
        }

        // The exception must be re-thrown
        $this->assertNotNull($caughtException, 'Exception must be re-thrown');
        $this->assertSame($expectedException, $caughtException);

        // The exception must be recorded on the span
        $this->assertCount(1, $this->recordingSpan->recordedExceptions, 'Exactly one exception should be recorded');
        $this->assertSame($expectedException, $this->recordingSpan->recordedExceptions[0]);

        // Span status must be ERROR
        $this->assertSame(StatusCode::STATUS_ERROR, $this->recordingSpan->statusCode);
        $this->assertSame('SQLSTATE[23000]: Integrity constraint violation', $this->recordingSpan->statusDescription);

        // Span must still be ended even on error
        $this->assertTrue($this->recordingSpan->ended, 'Span must be ended even on error');
    }

    /**
     * Test: DSN without port still extracts db name correctly.
     *
     * Validates: Requirements 2.3
     */
    public function testDsnWithoutPortExtractsDbNameCorrectly(): void
    {
        $tracer = $this->createRecordingTracer();
        $this->injectTracer($tracer);

        $dsn = 'mysql:host=db-server;dbname=tenant_school_42';
        $connection = $this->createMockConnection($dsn, 'masterDb');

        $sql = 'SELECT COUNT(*) FROM tenants';
        $params = [];

        DbInstrumentation::wrapWithSpan($sql, $params, $connection, fn() => 15);

        $this->assertSame('tenant_school_42', $this->recordingSpan->attributes['db.name']);
        $this->assertSame('masterDb', $this->recordingSpan->attributes['db.connection_name']);
    }
}
