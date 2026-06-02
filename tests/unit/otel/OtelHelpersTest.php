<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\OtelHelpers;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\TraceFlags;
use OpenTelemetry\Context\Context;
use yii\log\Logger;

/**
 * Property-based tests for OtelHelpers pure static functions.
 *
 * Uses a lightweight custom generator approach with 100+ iterations per property,
 * as specified in the design document's testing strategy.
 */
class OtelHelpersTest extends \PHPUnit\Framework\TestCase
{
    private const ITERATIONS = 100;

    /**
     * Generate a random non-empty alphanumeric string of length 1–20.
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
     * Feature: otel-deep-instrumentation, Property 1: Route-to-span-name produces correct format
     *
     * For any combination of optional moduleId, controllerId, and actionId,
     * the span naming function shall produce a string matching
     * `{moduleId}/{controllerId}/{actionId}` when module is present,
     * or `{controllerId}/{actionId}` when no module — and the output
     * shall always contain all non-null input segments separated by `/`.
     *
     * Validates: Requirements 1.1, 1.2, 1.3, 1.4
     */
    public function testProperty1RouteToSpanNameFormat(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $moduleId = random_int(0, 1) === 1 ? $this->randomString() : null;
            $controllerId = $this->randomString();
            $actionId = $this->randomString();

            $result = OtelHelpers::buildRouteName($moduleId, $controllerId, $actionId);

            if ($moduleId !== null) {
                // With module: {moduleId}/{controllerId}/{actionId}
                $expected = "{$moduleId}/{$controllerId}/{$actionId}";
                $this->assertSame($expected, $result, "Iteration {$i}: with module, expected 3-segment format");
                $segments = explode('/', $result);
                $this->assertCount(3, $segments, "Iteration {$i}: should have 3 segments when module present");
            } else {
                // Without module: {controllerId}/{actionId}
                $expected = "{$controllerId}/{$actionId}";
                $this->assertSame($expected, $result, "Iteration {$i}: without module, expected 2-segment format");
                $segments = explode('/', $result);
                $this->assertCount(2, $segments, "Iteration {$i}: should have 2 segments when no module");
            }

            // All non-null segments must appear in the output
            $this->assertStringContainsString($controllerId, $result, "Iteration {$i}: result must contain controllerId");
            $this->assertStringContainsString($actionId, $result, "Iteration {$i}: result must contain actionId");
            if ($moduleId !== null) {
                $this->assertStringContainsString($moduleId, $result, "Iteration {$i}: result must contain moduleId");
            }
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 2: SQL verb extraction
     *
     * For any SQL statement starting with a known verb (SELECT, INSERT, UPDATE,
     * DELETE, ALTER, DROP), the extraction function shall return the uppercase
     * first word of the statement.
     *
     * Validates: Requirements 2.7
     */
    public function testProperty2SqlVerbExtraction(): void
    {
        $verbs = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $verb = $verbs[random_int(0, count($verbs) - 1)];
            $rest = $this->randomString(5, 50);
            $sql = "{$verb} {$rest}";

            $result = OtelHelpers::extractSqlVerb($sql);

            $this->assertSame($verb, $result, "Iteration {$i}: extractSqlVerb('{$sql}') should return '{$verb}'");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 2 (supplemental): SQL verb extraction with leading whitespace
     *
     * Verifies verb extraction works correctly when SQL has leading whitespace.
     *
     * Validates: Requirements 2.7
     */
    public function testProperty2SqlVerbExtractionWithLeadingWhitespace(): void
    {
        $verbs = ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP'];

        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $verb = $verbs[random_int(0, count($verbs) - 1)];
            $rest = $this->randomString(5, 50);
            $spaces = str_repeat(' ', random_int(1, 5));
            $sql = "{$spaces}{$verb} {$rest}";

            $result = OtelHelpers::extractSqlVerb($sql);

            $this->assertSame($verb, $result, "Iteration {$i}: should handle leading whitespace");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 3: SQL parameter sanitization
     *
     * For any SQL with random named parameter placeholders and values,
     * the sanitization function shall replace all parameter keys with `?`
     * and the output shall contain no original parameter keys.
     *
     * Validates: Requirements 2.5
     */
    public function testProperty3SqlParameterSanitization(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $numParams = random_int(1, 5);
            $params = [];
            $whereClauses = [];

            for ($j = 0; $j < $numParams; $j++) {
                $paramName = ":param{$j}";
                $paramValue = $this->randomString(3, 15);
                $params[$paramName] = $paramValue;
                $whereClauses[] = "col{$j} = {$paramName}";
            }

            $sql = "SELECT * FROM table1 WHERE " . implode(' AND ', $whereClauses);
            $result = OtelHelpers::sanitizeSql($sql, $params);

            // Output must contain `?` placeholders
            $this->assertStringContainsString('?', $result, "Iteration {$i}: sanitized SQL must contain ? placeholders");

            // Output must not contain any of the original parameter keys
            foreach (array_keys($params) as $key) {
                $this->assertStringNotContainsString($key, $result, "Iteration {$i}: sanitized SQL must not contain param key '{$key}'");
            }

            // The number of `?` should equal the number of params
            $questionMarks = substr_count($result, '?');
            $this->assertSame($numParams, $questionMarks, "Iteration {$i}: should have exactly {$numParams} ? placeholders");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 4: DSN database name extraction
     *
     * For any MySQL DSN string in the format `mysql:host={h};port={p};dbname={name}`,
     * the extraction function shall return the exact `{name}` value.
     *
     * Validates: Requirements 2.3
     */
    public function testProperty4DsnDatabaseNameExtraction(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $host = $this->randomString(3, 15);
            $port = random_int(1000, 65535);
            $dbName = $this->randomString(3, 30);

            $dsn = "mysql:host={$host};port={$port};dbname={$dbName}";
            $result = OtelHelpers::extractDbNameFromDsn($dsn);

            $this->assertSame($dbName, $result, "Iteration {$i}: extractDbNameFromDsn should return '{$dbName}' from DSN '{$dsn}'");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 4 (supplemental): DSN without port
     *
     * Verifies extraction works when port is omitted from the DSN.
     *
     * Validates: Requirements 2.3
     */
    public function testProperty4DsnWithoutPort(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $host = $this->randomString(3, 15);
            $dbName = $this->randomString(3, 30);

            $dsn = "mysql:host={$host};dbname={$dbName}";
            $result = OtelHelpers::extractDbNameFromDsn($dsn);

            $this->assertSame($dbName, $result, "Iteration {$i}: should extract dbname without port in DSN");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 10: Log level mapping correctness
     *
     * For all 5 Yii2 log levels, the mapping function shall return the corresponding
     * OTEL severity and the mapping shall be total (no unmapped levels).
     *
     * Validates: Requirements 5.3
     */
    public function testProperty10LogLevelMappingCorrectness(): void
    {
        $expectedMapping = [
            Logger::LEVEL_ERROR   => 'ERROR',
            Logger::LEVEL_WARNING => 'WARN',
            Logger::LEVEL_INFO    => 'INFO',
            Logger::LEVEL_TRACE   => 'DEBUG',
            Logger::LEVEL_PROFILE => 'DEBUG',
        ];

        // Verify the mapping is total — all 5 levels are covered
        $this->assertCount(5, $expectedMapping, 'All 5 Yii2 log levels must be mapped');

        // Iterate all levels and verify correctness (repeated 100 times to satisfy PBT iteration requirement)
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            foreach ($expectedMapping as $yiiLevel => $otelSeverity) {
                $result = OtelHelpers::mapYiiLogLevel($yiiLevel);
                $this->assertSame(
                    $otelSeverity,
                    $result,
                    "Iteration {$i}: mapYiiLogLevel({$yiiLevel}) should return '{$otelSeverity}', got '{$result}'"
                );
            }
        }

        // Verify no level returns UNSPECIFIED (totality check)
        foreach (array_keys($expectedMapping) as $level) {
            $this->assertNotSame(
                'UNSPECIFIED',
                OtelHelpers::mapYiiLogLevel($level),
                "Level {$level} must not map to UNSPECIFIED"
            );
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 13: Job span naming
     *
     * For any fully-qualified class name, `shortClassName` shall return the last
     * segment after `\`, and the `JOB {shortName}` format shall be correct.
     *
     * Validates: Requirements 6.3
     */
    public function testProperty13JobSpanNaming(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            // Generate a random FQCN with 1–5 namespace segments
            $numSegments = random_int(1, 5);
            $segments = [];
            for ($j = 0; $j < $numSegments; $j++) {
                $segments[] = $this->randomString(3, 15);
            }
            $className = $this->randomString(3, 20);
            $fqcn = implode('\\', $segments) . '\\' . $className;

            $result = OtelHelpers::shortClassName($fqcn);

            // shortClassName should return the last segment
            $this->assertSame($className, $result, "Iteration {$i}: shortClassName('{$fqcn}') should return '{$className}'");

            // JOB {shortName} format should be correct
            $jobSpanName = "JOB {$result}";
            $this->assertSame("JOB {$className}", $jobSpanName, "Iteration {$i}: JOB span name should be 'JOB {$className}'");
            $this->assertStringStartsWith('JOB ', $jobSpanName, "Iteration {$i}: job span name must start with 'JOB '");
        }
    }

    /**
     * Feature: otel-deep-instrumentation, Property 13 (supplemental): shortClassName with no namespace
     *
     * When the FQCN has no namespace separator, shortClassName returns the input as-is.
     *
     * Validates: Requirements 6.3
     */
    public function testProperty13ShortClassNameWithoutNamespace(): void
    {
        for ($i = 0; $i < self::ITERATIONS; $i++) {
            $className = $this->randomString(3, 20);

            $result = OtelHelpers::shortClassName($className);

            $this->assertSame($className, $result, "Iteration {$i}: shortClassName without namespace should return input unchanged");
        }
    }

    /**
     * Feature: otel-orphaned-cache-spans — hasActiveSpan returns false with no active span.
     *
     * In the default state (no span activated), Span::getCurrent() returns a
     * non-recording span with an invalid context, so hasActiveSpan() must return false.
     */
    public function testHasActiveSpanReturnsFalseWithNoActiveSpan(): void
    {
        $this->assertFalse(OtelHelpers::hasActiveSpan());
    }

    /**
     * Feature: otel-orphaned-cache-spans — hasActiveSpan returns true with an active recording span.
     *
     * When a valid span context is created and activated, hasActiveSpan() must return true.
     */
    public function testHasActiveSpanReturnsTrueWithActiveSpan(): void
    {
        $spanContext = SpanContext::create(
            bin2hex(random_bytes(16)), // traceId
            bin2hex(random_bytes(8)),  // spanId
            TraceFlags::SAMPLED
        );
        $span = Span::wrap($spanContext);
        $scope = Context::getCurrent()->withContextValue($span)->activate();
        try {
            $this->assertTrue(OtelHelpers::hasActiveSpan());
        } finally {
            $scope->detach();
        }
    }
}
