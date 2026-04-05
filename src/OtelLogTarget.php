<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Logs\Severity;
use OpenTelemetry\API\Trace\Span;
use yii\log\Logger;
use yii\log\Target;

/**
 * Forwards Yii2 log messages to the OpenTelemetry Log Exporter with trace correlation.
 *
 * Each Yii2 log message is converted to an OTEL LogRecord with:
 * - Severity mapped from Yii2 log levels via OtelHelpers::mapYiiLogLevel()
 * - trace_id and span_id from the current active span context (if valid)
 * - log.category attribute from the Yii2 log category
 *
 * Only active when OTEL_LOGS_EXPORTER=otlp.
 *
 * Configurable as a standard Yii2 log target in the application log.targets array.
 *
 * Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6
 */
class OtelLogTarget extends Target
{
    private const NANOS_PER_SECOND = 1_000_000_000;

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger, $config = [])
    {
        $this->logger = $logger;
        parent::__construct($config);
    }

    /**
     * Exports collected log messages to the OTEL Log Exporter.
     *
     * Each Yii2 log message is an array: [message, level, category, timestamp, traces]
     */
    public function export(): void
    {
        foreach ($this->messages as $message) {
            [$text, $level, $category, $timestamp] = $message;

            $severityText = OtelHelpers::mapYiiLogLevel($level);
            $severityNumber = self::mapSeverityNumber($level);

            // Convert message body to string
            $body = \is_string($text) ? $text : print_r($text, true);

            // Build the log record using the builder API
            $logRecordBuilder = $this->logger->logRecordBuilder()
                ->setBody($body)
                ->setSeverityNumber($severityNumber)
                ->setSeverityText($severityText)
                ->setTimestamp((int) ($timestamp * self::NANOS_PER_SECOND))
                ->setAttribute('log.category', $category);

            // Attach trace context if an active span exists with a valid context
            $span = Span::getCurrent();
            $spanContext = $span->getContext();
            if ($spanContext->isValid()) {
                $logRecordBuilder->setAttribute('trace_id', $spanContext->getTraceId());
                $logRecordBuilder->setAttribute('span_id', $spanContext->getSpanId());
            }

            $logRecordBuilder->emit();
        }
    }

    /**
     * Maps a Yii2 Logger level constant to an OTEL Severity enum value.
     *
     * @param int $level The Yii2 Logger level constant
     * @return Severity The OTEL Severity enum value
     */
    private static function mapSeverityNumber(int $level): Severity
    {
        return match ($level) {
            Logger::LEVEL_ERROR => Severity::ERROR,
            Logger::LEVEL_WARNING => Severity::WARN,
            Logger::LEVEL_INFO => Severity::INFO,
            Logger::LEVEL_TRACE => Severity::DEBUG,
            Logger::LEVEL_PROFILE => Severity::DEBUG,
            default => Severity::INFO,
        };
    }
}
