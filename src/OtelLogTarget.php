<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\LogRecord;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\Context\Context;
use yii\log\Logger;
use yii\log\Target;

/**
 * Forwards Yii2 log messages to the OpenTelemetry Log Exporter with trace correlation.
 *
 * Each Yii2 log message is converted to an OTEL LogRecord with:
 * - Severity mapped from Yii2 log levels
 * - Trace context attached via setContext() for automatic SigNoz log-trace correlation
 * - log.category attribute from the Yii2 log category
 * - Full exception stack trace when the message body is a Throwable
 *
 * Only active when OTEL_LOGS_EXPORTER=otlp.
 *
 * Default behaviour:
 * - Only ships WARNING and ERROR levels (DEBUG/TRACE/INFO are too noisy by default)
 * - Excludes internal Yii categories already captured as spans (db, http, profile)
 * - Flushes every 50 messages for near-real-time visibility
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
 */
class OtelLogTarget extends Target
{
    private const NANOS_PER_SECOND = 1_000_000_000;

    /**
     * Log levels to export. Defaults to WARNING + ERROR only.
     * Override to include Logger::LEVEL_INFO etc. if needed.
     */
    public int $exportLevels = Logger::LEVEL_ERROR | Logger::LEVEL_WARNING;

    /**
     * Category prefixes to exclude from export.
     * These are already captured as spans or are too noisy.
     */
    public array $excludeCategories = [
        'yii\db\Command',
        'yii\db\Connection',
        'yii\web\HttpException:404',
        'yii\web\HttpException:403',
        'yii\web\HttpException:400',
        'yii\debug\*',
    ];

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, $config = [])
    {
        $this->logger = $logger;

        // Flush every 50 messages for near-real-time visibility in SigNoz
        $this->logVars = [];

        parent::__construct($config);

        // Apply default level filter (can be overridden via $config)
        if (empty($this->levels)) {
            $this->setLevels($this->exportLevels);
        }

        // Apply default category exclusions
        if (empty($this->except)) {
            $this->except = $this->excludeCategories;
        }
    }

    /**
     * Exports collected log messages to the OTEL Log Exporter.
     *
     * Each Yii2 log message is an array: [message, level, category, timestamp, traces]
     *
     * Trace context is attached via setContext() so SigNoz can correlate logs
     * with traces using standard W3C trace fields automatically.
     */
    public function export(): void
    {
        foreach ($this->messages as $message) {
            [$text, $level, $category, $timestamp] = $message;

            $body = $this->formatBody($text);

            $logRecord = (new LogRecord($body))
                ->setSeverityNumber(self::mapSeverityNumber($level))
                ->setSeverityText(OtelHelpers::mapYiiLogLevel($level))
                ->setTimestamp((int) ($timestamp * self::NANOS_PER_SECOND))
                ->setAttribute('log.category', $category)
                ->setContext(Context::getCurrent());

            // Attach exception details as structured attributes if available
            if ($text instanceof \Throwable) {
                $logRecord
                    ->setAttribute('exception.type', \get_class($text))
                    ->setAttribute('exception.message', $text->getMessage())
                    ->setAttribute('exception.stacktrace', $text->getTraceAsString());
            }

            $this->logger->emit($logRecord);
        }

        $this->messages = [];
    }

    /**
     * Formats the log message body to a string.
     *
     * - Throwables: formatted as "ExceptionClass: message\n{stacktrace}"
     * - Arrays/objects: JSON-encoded for structured readability
     * - Strings: passed through as-is
     */
    private function formatBody(mixed $text): string
    {
        if ($text instanceof \Throwable) {
            return \sprintf(
                "%s: %s\n\nStack trace:\n%s",
                \get_class($text),
                $text->getMessage(),
                $text->getTraceAsString()
            );
        }

        if (\is_array($text) || \is_object($text)) {
            $encoded = \json_encode($text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return $encoded !== false ? $encoded : print_r($text, true);
        }

        return (string) $text;
    }

    /**
     * Maps a Yii2 Logger level constant to an OTEL Severity enum value.
     */
    private static function mapSeverityNumber(int $level): Severity
    {
        return match ($level) {
            Logger::LEVEL_ERROR   => Severity::ERROR,
            Logger::LEVEL_WARNING => Severity::WARN,
            Logger::LEVEL_INFO    => Severity::INFO,
            Logger::LEVEL_TRACE   => Severity::DEBUG,
            Logger::LEVEL_PROFILE => Severity::DEBUG,
            default               => Severity::INFO,
        };
    }
}
