<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use SplStack;
use yii\base\Event;

/**
 * Creates child spans for outbound HTTP requests via yii\httpclient\Client.
 *
 * Hooks into Client::EVENT_BEFORE_SEND and EVENT_AFTER_SEND to wrap each
 * outbound HTTP request with an OTEL span of kind KIND_CLIENT.
 *
 * Uses a stack for concurrent/nested requests.
 *
 * Note: yii\httpclient\Client may not be available if yiisoft/yii2-httpclient
 * is not installed. The register() method checks class_exists before hooking.
 *
 * Requirements: 20.1, 20.2, 20.3, 20.4, 20.5, 20.6, 20.7
 */
class HttpClientInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /**
     * @var SplStack<array{span: SpanInterface, scope: ScopeInterface}>|null
     * Stack of active HTTP client spans for handling concurrent requests.
     */
    private static ?SplStack $spanStack = null;

    /**
     * Registers HTTP client instrumentation by hooking into Client events.
     *
     * Checks that yii\httpclient\Client exists before registering to avoid
     * errors when yiisoft/yii2-httpclient is not installed.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        if (!\class_exists('yii\httpclient\Client')) {
            return;
        }

        self::$tracer = $tracer;
        self::$spanStack = new SplStack();

        Event::on(
            'yii\httpclient\Client',
            'beforeSend',
            [static::class, 'handleBeforeSend']
        );
        Event::on(
            'yii\httpclient\Client',
            'afterSend',
            [static::class, 'handleAfterSend']
        );
    }

    /**
     * Handles EVENT_BEFORE_SEND: creates a KIND_CLIENT span for the outbound request.
     *
     * @param Event $event The event containing the request
     */
    public static function handleBeforeSend(Event $event): void
    {
        if (self::$tracer === null || self::$spanStack === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        try {
            /** @var \yii\httpclient\Request $request */
            $request = $event->request;
            $fullUrl = $request->getFullUrl();
            $method = \strtoupper($request->getMethod());
            $host = \parse_url($fullUrl, PHP_URL_HOST) ?: 'unknown';

            $spanName = "HTTP {$method} {$host}";

            $span = self::$tracer->spanBuilder($spanName)
                ->setSpanKind(SpanKind::KIND_CLIENT)
                ->setAttribute('http.method', $method)
                ->setAttribute('http.url', $fullUrl)
                ->setAttribute('http.host', $host)
                ->startSpan();

            $scope = $span->activate();

            // Inject W3C traceparent/tracestate into outbound request headers
            $carrier = [];
            Globals::propagator()->inject($carrier, null, Context::getCurrent());
            foreach ($carrier as $headerName => $headerValue) {
                $request->addHeaders([$headerName => $headerValue]);
            }

            self::$spanStack->push(['span' => $span, 'scope' => $scope]);
        } catch (\Throwable $e) {
            // Silently fail — don't break HTTP requests
        }
    }

    /**
     * Handles EVENT_AFTER_SEND: sets http.status_code and ends the span.
     *
     * @param Event $event The event containing the response
     */
    public static function handleAfterSend(Event $event): void
    {
        if (self::$spanStack === null || self::$spanStack->isEmpty()) {
            return;
        }

        try {
            $entry = self::$spanStack->pop();

            /** @var \yii\httpclient\Response $response */
            $response = $event->response;
            if ($response !== null) {
                $entry['span']->setAttribute('http.status_code', $response->getStatusCode());
            }

            $entry['span']->end();
            $entry['scope']->detach();
        } catch (\Throwable $e) {
            // Silently fail — don't break HTTP requests
        }
    }

    /**
     * Cleans up an HTTP client span from the stack when a transport error occurs.
     *
     * @param \Throwable $exception The transport exception
     */
    public static function handleException(\Throwable $exception): void
    {
        if (self::$spanStack === null || self::$spanStack->isEmpty()) {
            return;
        }

        try {
            $entry = self::$spanStack->pop();
            $entry['span']->recordException($exception);
            $entry['span']->setStatus(StatusCode::STATUS_ERROR, $exception->getMessage());
            $entry['span']->end();
            $entry['scope']->detach();
        } catch (\Throwable $e) {
            // Silently fail
        }
    }

    /**
     * Returns the span stack (for testing purposes).
     *
     * @return SplStack|null
     */
    public static function getSpanStack(): ?SplStack
    {
        return self::$spanStack;
    }
}
