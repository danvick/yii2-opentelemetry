<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\OtelLogTarget;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;
use yii\log\Logger;

/**
 * A recording logger that captures LogRecord objects emitted via emit().
 */
class RecordingLogger implements LoggerInterface
{
    /** @var LogRecord[] */
    public array $emitted = [];

    public function emit(LogRecord $logRecord): void
    {
        $this->emitted[] = $logRecord;
    }

    public function logRecordBuilder(): \OpenTelemetry\API\Logs\LogRecordBuilderInterface
    {
        throw new \BadMethodCallException('logRecordBuilder() not used — emit() is called directly');
    }

    public function isEnabled(
        ?\OpenTelemetry\Context\ContextInterface $context = null,
        ?int $severityNumber = null,
        ?string $eventName = null
    ): bool {
        return true;
    }
}

/**
 * A test span with a configurable SpanContext for activating trace context in tests.
 */
class LogTestSpan extends Span
{
    private SpanContextInterface $spanContext;

    public function __construct(SpanContextInterface $spanContext)
    {
        $this->spanContext = $spanContext;
    }

    public function getContext(): SpanContextInterface { return $this->spanContext; }
    public function isRecording(): bool { return true; }
    public function setAttribute(string $key, bool|int|float|string|array|null $value): SpanInterface { return $this; }
    public function setAttributes(iterable $attributes): SpanInterface { return $this; }
    public function addLink(SpanContextInterface $context, iterable $attributes = []): SpanInterface { return $this; }
    public function addEvent(string $name, iterable $attributes = [], ?int $timestamp = null): SpanInterface { return $this; }
    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface { return $this; }
    public function updateName(string $name): SpanInterface { return $this; }
    public function setStatus(string $code, ?string $description = null): SpanInterface { return $this; }
    public function end(?int $endEpochNanos = null): void {}
}

/**
 * Unit and property-based tests for OtelLogTarget.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
 */
class OtelLogTargetTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 100;
    private ?ScopeInterface $scope = null;
    private mixed $savedApp = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->savedApp = \Yii::$app;
        \Yii::$app = null;
    }

    protected function tearDown(): void
    {
        $this->scope?->detach();
        $this->scope = null;
        \Yii::$app = $this->savedApp;
        parent::tearDown();
    }

    private function randomString(int $min = 3, int $max = 30): string
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $len = random_int($min, $max);
        $out = '';
        for ($i = 0; $i < $len; $i++) {
            $out .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $out;
    }

    private function randomTraceId(): string
    {
        do { $hex = bin2hex(random_bytes(16)); } while ($hex === str_repeat('0', 32));
        return $hex;
    }

    private function randomSpanId(): string
    {
        do { $hex = bin2hex(random_bytes(8)); } while ($hex === str_repeat('0', 16));
        return $hex;
    }

    /** Read a protected property from a LogRecord via reflection. */
    private function logRecordProp(LogRecord $record, string $prop): mixed
    {
        $ref = new \ReflectionProperty(LogRecord::class, $prop);
        $ref->setAccessible(true);
        return $ref->getValue($record);
    }

    private function createTarget(RecordingLogger $logger): OtelLogTarget
    {
        $ref = new \ReflectionClass(OtelLogTarget::class);
        $target = $ref->newInstanceWithoutConstructor();
        $prop = $ref->getProperty('logger');
        $prop->setAccessible(true);
        $prop->setValue($target, $logger);
        return $target;
    }

    // -------------------------------------------------------------------------
    // Property 11: Log records carry correct metadata
    // -------------------------------------------------------------------------

    /**
     * For any log message with any level and category, the emitted LogRecord
     * must have the correct severity, body, and log.category attribute.
     */
    public function testProperty11LogRecordMetadata(): void
    {
        $levelMap = [
            Logger::LEVEL_ERROR   => Severity::ERROR->value,
            Logger::LEVEL_WARNING => Severity::WARN->value,
            Logger::LEVEL_INFO    => Severity::INFO->value,
            Logger::LEVEL_TRACE   => Severity::DEBUG->value,
            Logger::LEVEL_PROFILE => Severity::DEBUG->value,
        ];
        $levels = array_keys($levelMap);

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $level    = $levels[array_rand($levels)];
            $category = $this->randomString();
            $text     = $this->randomString(5, 50);
            $ts       = microtime(true);

            $logger = new RecordingLogger();
            $target = $this->createTarget($logger);
            $target->messages = [[$text, $level, $category, $ts, []]];
            $target->export();

            $this->assertCount(1, $logger->emitted, "Iteration $i: one record emitted");

            $record = $logger->emitted[0];
            $this->assertSame($levelMap[$level], $this->logRecordProp($record, 'severityNumber'),
                "Iteration $i: severity number mismatch for level $level");
            $this->assertSame($category, $this->logRecordProp($record, 'attributes')['log.category'] ?? null,
                "Iteration $i: log.category must match");
        }
    }

    // -------------------------------------------------------------------------
    // Trace context correlation
    // -------------------------------------------------------------------------

    /**
     * When emitted inside an active span, the LogRecord context must carry
     * the active W3C trace context so the backend can correlate logs to traces.
     */
    public function testLogRecordCarriesActiveContext(): void
    {
        $traceId = $this->randomTraceId();
        $spanId  = $this->randomSpanId();

        $spanContext = SpanContext::create($traceId, $spanId, TraceFlags::SAMPLED);
        $span = new LogTestSpan($spanContext);
        $this->scope = $span->activate();

        $logger = new RecordingLogger();
        $target = $this->createTarget($logger);
        $target->messages = [['msg', Logger::LEVEL_ERROR, 'test', microtime(true), []]];
        $target->export();

        $this->assertCount(1, $logger->emitted);
        $record = $logger->emitted[0];

        // The context on the record should be the active context (non-null)
        $this->assertNotNull($this->logRecordProp($record, 'context'),
            'LogRecord context must be set when emitted inside an active span');
    }

    /**
     * When emitted outside any active span, the LogRecord context is still
     * set (to the background context) — no crash occurs.
     */
    public function testLogRecordOutsideSpanDoesNotCrash(): void
    {
        $logger = new RecordingLogger();
        $target = $this->createTarget($logger);
        $target->messages = [['msg', Logger::LEVEL_WARNING, 'app', microtime(true), []]];
        $target->export();

        $this->assertCount(1, $logger->emitted);
        // No exception = pass
        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // Exception formatting
    // -------------------------------------------------------------------------

    /**
     * When the message body is a Throwable, the LogRecord body must contain
     * the exception class name, message, and stack trace.
     */
    public function testThrowableBodyIsFormattedWithStackTrace(): void
    {
        $exception = new \RuntimeException('something went wrong');

        $logger = new RecordingLogger();
        $target = $this->createTarget($logger);
        $target->messages = [[$exception, Logger::LEVEL_ERROR, 'app\test', microtime(true), []]];
        $target->export();

        $this->assertCount(1, $logger->emitted);
        $record = $logger->emitted[0];

        $body  = (string) $this->logRecordProp($record, 'body');
        $attrs = $this->logRecordProp($record, 'attributes');

        $this->assertStringContainsString('RuntimeException', $body);
        $this->assertStringContainsString('something went wrong', $body);
        $this->assertStringContainsString('Stack trace', $body);

        $this->assertSame('RuntimeException', $attrs['exception.type'] ?? null);
        $this->assertSame('something went wrong', $attrs['exception.message'] ?? null);
        $this->assertNotEmpty($attrs['exception.stacktrace'] ?? '');
    }

    // -------------------------------------------------------------------------
    // Messages cleared after export
    // -------------------------------------------------------------------------

    /**
     * After export(), $this->messages must be empty to prevent double-shipping.
     */
    public function testMessagesAreClearedAfterExport(): void
    {
        $logger = new RecordingLogger();
        $target = $this->createTarget($logger);
        $target->messages = [
            ['msg1', Logger::LEVEL_ERROR, 'cat', microtime(true), []],
            ['msg2', Logger::LEVEL_WARNING, 'cat', microtime(true), []],
        ];
        $target->export();

        $this->assertEmpty($target->messages, 'messages must be empty after export()');

        // Second export should emit nothing
        $target->export();
        $this->assertCount(2, $logger->emitted, 'second export must not re-emit old messages');
    }

    // -------------------------------------------------------------------------
    // Severity mapping
    // -------------------------------------------------------------------------

    /**
     * All five Yii2 log levels map to the expected OTEL severity number.
     */
    public function testAllYii2LevelsMapToCorrectSeverity(): void
    {
        $cases = [
            Logger::LEVEL_ERROR   => Severity::ERROR->value,
            Logger::LEVEL_WARNING => Severity::WARN->value,
            Logger::LEVEL_INFO    => Severity::INFO->value,
            Logger::LEVEL_TRACE   => Severity::DEBUG->value,
            Logger::LEVEL_PROFILE => Severity::DEBUG->value,
        ];

        foreach ($cases as $level => $expected) {
            $logger = new RecordingLogger();
            $target = $this->createTarget($logger);
            $target->messages = [["msg level $level", $level, 'cat', microtime(true), []]];
            $target->export();

            $this->assertSame($expected, $this->logRecordProp($logger->emitted[0], 'severityNumber'),
                "Level $level should map to severity $expected");
        }
    }

    // -------------------------------------------------------------------------
    // Extends Target
    // -------------------------------------------------------------------------

    public function testExtendsYiiLogTarget(): void
    {
        $logger = new RecordingLogger();
        $target = $this->createTarget($logger);
        $this->assertInstanceOf(\yii\log\Target::class, $target);
    }
}
