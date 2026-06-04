<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use yii\base\Event;
use yii\mail\BaseMailer;
use yii\mail\MailEvent;

/**
 * Creates child spans for mail sending operations.
 *
 * Hooks into BaseMailer::EVENT_BEFORE_SEND and EVENT_AFTER_SEND to wrap
 * each mail send with an OTEL span.
 *
 * Uses instance properties (not a stack) since mail sends don't typically nest.
 *
 * Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6, 22.7
 */
class MailInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /** @var SpanInterface|null The active mail send span */
    private static ?SpanInterface $mailSpan = null;

    /** @var ScopeInterface|null The scope for the active mail send span */
    private static ?ScopeInterface $mailScope = null;

    /**
     * Registers mail instrumentation by hooking into BaseMailer events.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;

        Event::on(BaseMailer::class, BaseMailer::EVENT_BEFORE_SEND, [static::class, 'handleBeforeSend']);
        Event::on(BaseMailer::class, BaseMailer::EVENT_AFTER_SEND, [static::class, 'handleAfterSend']);
    }

    /**
     * Handles EVENT_BEFORE_SEND: creates a MAIL SEND span with recipient and subject.
     *
     * @param MailEvent $event The mail event containing the message
     */
    public static function handleBeforeSend(MailEvent $event): void
    {
        if (self::$tracer === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        try {
            // If a previous span was never ended (e.g. exception between before/after
            // events), clean it up before starting a new one to prevent span leaks
            // and incorrect parent-child nesting for subsequent operations.
            if (self::$mailSpan !== null) {
                self::$mailSpan->end();
                self::$mailSpan = null;
                self::$mailScope?->detach();
                self::$mailScope = null;
            }

            $message = $event->message;
            $spanBuilder = self::$tracer->spanBuilder('MAIL SEND');

            // Set mail.to from recipients
            $to = $message->getTo();
            if (\is_array($to)) {
                // getTo() returns [email => name] or [0 => email]
                $recipients = [];
                foreach ($to as $key => $value) {
                    $recipients[] = \is_int($key) ? $value : $key;
                }
                $spanBuilder->setAttribute('mail.to', \implode(', ', $recipients));
            } elseif (\is_string($to)) {
                $spanBuilder->setAttribute('mail.to', $to);
            }

            // Set mail.subject
            $subject = $message->getSubject();
            if ($subject !== null) {
                $spanBuilder->setAttribute('mail.subject', $subject);
            }

            $span = $spanBuilder->startSpan();
            $scope = $span->activate();

            self::$mailSpan = $span;
            self::$mailScope = $scope;
        } catch (\Throwable $e) {
            // Silently fail — don't break mail sending
        }
    }

    /**
     * Handles EVENT_AFTER_SEND: sets mail.success and ends the span.
     *
     * @param MailEvent $event The mail event with isSuccessful flag
     */
    public static function handleAfterSend(MailEvent $event): void
    {
        if (self::$mailSpan === null) {
            return;
        }

        try {
            self::$mailSpan->setAttribute('mail.success', $event->isSuccessful);

            if (!$event->isSuccessful) {
                self::$mailSpan->setStatus(StatusCode::STATUS_ERROR, 'Mail send failed');
            }

            self::$mailSpan->end();
        } catch (\Throwable $e) {
            // Silently fail
        } finally {
            if (self::$mailScope !== null) {
                self::$mailScope->detach();
            }
            self::$mailSpan = null;
            self::$mailScope = null;
        }
    }

    /**
     * Cleans up the mail span when an exception occurs during sending.
     *
     * @param \Throwable $exception The exception that occurred
     */
    public static function handleException(\Throwable $exception): void
    {
        if (self::$mailSpan === null) {
            return;
        }

        try {
            self::$mailSpan->recordException($exception);
            self::$mailSpan->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            self::$mailSpan->end();
        } catch (\Throwable $e) {
            // Silently fail
        } finally {
            if (self::$mailScope !== null) {
                self::$mailScope->detach();
            }
            self::$mailSpan = null;
            self::$mailScope = null;
        }
    }

    /**
     * Returns the active mail span (for testing purposes).
     *
     * @return SpanInterface|null
     */
    public static function getMailSpan(): ?SpanInterface
    {
        return self::$mailSpan;
    }
}
