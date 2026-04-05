<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\Span;
use yii\base\ActionEvent;
use yii\base\Application;

/**
 * Renames the root span to the resolved Yii2 route after action dispatch.
 *
 * For web apps, the span name becomes `{moduleId}/{controllerId}/{actionId}`
 * (or `{controllerId}/{actionId}` if the controller is not inside a module).
 * For console apps, the span name becomes `console/{route}`.
 *
 * If no action was dispatched (e.g., 404 before routing), the original span name
 * set by OtelBootstrap is retained.
 *
 * Requirements: 5.1, 5.2, 5.3, 5.4, 5.5
 */
class RouteResolver
{
    /**
     * Registers the EVENT_AFTER_ACTION handler on the given application.
     *
     * @param Application $app The Yii2 application instance (web or console)
     */
    public static function register(Application $app): void
    {
        $app->on(Application::EVENT_AFTER_ACTION, [static::class, 'handleAfterAction']);
    }

    /**
     * Handles the afterAction event by renaming the current OTEL span
     * to the resolved Yii2 route.
     *
     * @param ActionEvent $event The action event fired after action execution
     */
    public static function handleAfterAction(ActionEvent $event): void
    {
        $action = $event->action;
        if ($action === null) {
            return;
        }

        $app = \Yii::$app;
        $span = Span::getCurrent();

        if ($app instanceof \yii\console\Application) {
            $route = OtelHelpers::buildConsoleRouteName($app->requestedRoute);
        } else {
            $module = $action->controller->module;

            // Include moduleId only if the controller's module is a real Module
            // and not the application itself
            $moduleId = null;
            if ($module instanceof \yii\base\Module && !($module instanceof Application)) {
                $moduleId = $module->id;
            }

            $route = OtelHelpers::buildRouteName(
                $moduleId,
                $action->controller->id,
                $action->id
            );
        }

        $span->updateName($route);
    }
}
