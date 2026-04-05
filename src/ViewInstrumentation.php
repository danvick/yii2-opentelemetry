<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use SplStack;
use yii\base\Event;
use yii\base\ViewEvent;
use yii\base\View;

/**
 * Creates child spans for view rendering operations.
 *
 * Uses a stack (SplStack) to handle nested view rendering (layout → content
 * view → partials). Each EVENT_BEFORE_RENDER pushes a span+scope onto the
 * stack, and each EVENT_AFTER_RENDER pops and ends the top span.
 *
 * Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7
 */
class ViewInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /**
     * @var SplStack<array{span: SpanInterface, scope: ScopeInterface}>|null
     * Stack of active view spans for handling nested rendering.
     */
    private static ?SplStack $spanStack = null;

    /**
     * Registers view rendering instrumentation by hooking into View events.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;
        self::$spanStack = new SplStack();

        Event::on(View::class, View::EVENT_BEFORE_RENDER, [static::class, 'handleBeforeRender']);
        Event::on(View::class, View::EVENT_AFTER_RENDER, [static::class, 'handleAfterRender']);
    }

    /**
     * Handles EVENT_BEFORE_RENDER: creates a child span and pushes it onto the stack.
     *
     * @param ViewEvent $event The view event containing the view file path
     */
    public static function handleBeforeRender(ViewEvent $event): void
    {
        if (self::$tracer === null || self::$spanStack === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        $viewFile = $event->viewFile ?? '';
        $shortPath = self::shortenViewPath($viewFile);
        $spanName = "VIEW {$shortPath}";

        try {
            $span = self::$tracer->spanBuilder($spanName)
                ->setAttribute('view.file', $viewFile)
                ->startSpan();

            $scope = $span->activate();

            self::$spanStack->push(['span' => $span, 'scope' => $scope]);
        } catch (\Throwable $e) {
            // Silently fail — don't break rendering
        }
    }

    /**
     * Handles EVENT_AFTER_RENDER: pops the top span from the stack and ends it.
     *
     * @param ViewEvent $event The view event
     */
    public static function handleAfterRender(ViewEvent $event): void
    {
        if (self::$spanStack === null || self::$spanStack->isEmpty()) {
            return;
        }

        try {
            $entry = self::$spanStack->pop();
            $entry['span']->end();
            $entry['scope']->detach();
        } catch (\Throwable $e) {
            // Silently fail — don't break rendering
        }
    }

    /**
     * Cleans up a view span from the stack when an exception occurs during rendering.
     *
     * Should be called by error handlers to prevent stack corruption when a view
     * throws an exception between EVENT_BEFORE_RENDER and EVENT_AFTER_RENDER.
     */
    public static function handleRenderException(\Throwable $exception): void
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
     * Shortens a view file path by stripping the application's base path prefix.
     *
     * Converts `/var/www/html/backend/views/tenants/index.php` to `tenants/index`
     * by removing the base path and the `.php` extension.
     *
     * @param string $viewFile The full view file path
     * @return string The shortened view path
     */
    private static function shortenViewPath(string $viewFile): string
    {
        $shortPath = $viewFile;

        // Strip the app's base path prefix if available
        $app = \Yii::$app;
        if ($app !== null) {
            $basePath = $app->getBasePath();
            if (\str_starts_with($shortPath, $basePath)) {
                $shortPath = \substr($shortPath, \strlen($basePath));
            }
        }

        // Remove leading directory separators and common prefixes like /views/
        $shortPath = \ltrim($shortPath, '/\\');
        if (\str_starts_with($shortPath, 'views/')) {
            $shortPath = \substr($shortPath, 6);
        } elseif (\str_starts_with($shortPath, 'views\\')) {
            $shortPath = \substr($shortPath, 6);
        }

        // Remove .php extension
        if (\str_ends_with($shortPath, '.php')) {
            $shortPath = \substr($shortPath, 0, -4);
        }

        return $shortPath;
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
