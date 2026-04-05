<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\ScopeInterface;
use yii\base\Event;
use yii\web\User;
use yii\web\UserEvent;

/**
 * Creates child spans for authentication events (login, logout).
 *
 * Hooks into yii\web\User before/after login and logout events.
 * Uses instance properties (not a stack) since auth events don't nest.
 *
 * Requirements: 21.1, 21.2, 21.3, 21.4, 21.5, 21.6, 21.7
 */
class AuthInstrumentation
{
    private static ?TracerInterface $tracer = null;

    /** @var SpanInterface|null The active login span */
    private static ?SpanInterface $loginSpan = null;

    /** @var ScopeInterface|null The scope for the active login span */
    private static ?ScopeInterface $loginScope = null;

    /** @var SpanInterface|null The active logout span */
    private static ?SpanInterface $logoutSpan = null;

    /** @var ScopeInterface|null The scope for the active logout span */
    private static ?ScopeInterface $logoutScope = null;

    /**
     * Registers authentication instrumentation by hooking into User events.
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;

        Event::on(User::class, User::EVENT_BEFORE_LOGIN, [static::class, 'handleBeforeLogin']);
        Event::on(User::class, User::EVENT_AFTER_LOGIN, [static::class, 'handleAfterLogin']);
        Event::on(User::class, User::EVENT_BEFORE_LOGOUT, [static::class, 'handleBeforeLogout']);
        Event::on(User::class, User::EVENT_AFTER_LOGOUT, [static::class, 'handleAfterLogout']);
    }

    /**
     * Handles EVENT_BEFORE_LOGIN: creates an AUTH LOGIN span.
     *
     * @param UserEvent $event The user event containing the identity
     */
    public static function handleBeforeLogin(UserEvent $event): void
    {
        if (self::$tracer === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        try {
            $spanBuilder = self::$tracer->spanBuilder('AUTH LOGIN');

            // Set auth.user_id if identity is available
            if (isset($event->identity) && $event->identity !== null) {
                $spanBuilder->setAttribute('auth.user_id', (string) $event->identity->getId());
            }

            $span = $spanBuilder->startSpan();
            $scope = $span->activate();

            self::$loginSpan = $span;
            self::$loginScope = $scope;
        } catch (\Throwable $e) {
            // Silently fail — don't break authentication
        }
    }

    /**
     * Handles EVENT_AFTER_LOGIN: sets auth.success=true and ends the login span.
     *
     * @param UserEvent $event The user event
     */
    public static function handleAfterLogin(UserEvent $event): void
    {
        if (self::$loginSpan === null) {
            return;
        }

        try {
            self::$loginSpan->setAttribute('auth.success', true);
            self::$loginSpan->end();
        } catch (\Throwable $e) {
            // Silently fail
        } finally {
            if (self::$loginScope !== null) {
                self::$loginScope->detach();
            }
            self::$loginSpan = null;
            self::$loginScope = null;
        }
    }

    /**
     * Handles EVENT_BEFORE_LOGOUT: creates an AUTH LOGOUT span.
     *
     * @param UserEvent $event The user event containing the identity
     */
    public static function handleBeforeLogout(UserEvent $event): void
    {
        if (self::$tracer === null) {
            return;
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return;
        }

        try {
            $spanBuilder = self::$tracer->spanBuilder('AUTH LOGOUT');

            // Set auth.user_id if identity is available
            if (isset($event->identity) && $event->identity !== null) {
                $spanBuilder->setAttribute('auth.user_id', (string) $event->identity->getId());
            }

            $span = $spanBuilder->startSpan();
            $scope = $span->activate();

            self::$logoutSpan = $span;
            self::$logoutScope = $scope;
        } catch (\Throwable $e) {
            // Silently fail — don't break authentication
        }
    }

    /**
     * Handles EVENT_AFTER_LOGOUT: ends the logout span.
     *
     * @param UserEvent $event The user event
     */
    public static function handleAfterLogout(UserEvent $event): void
    {
        if (self::$logoutSpan === null) {
            return;
        }

        try {
            self::$logoutSpan->end();
        } catch (\Throwable $e) {
            // Silently fail
        } finally {
            if (self::$logoutScope !== null) {
                self::$logoutScope->detach();
            }
            self::$logoutSpan = null;
            self::$logoutScope = null;
        }
    }

    /**
     * Returns the active login span (for testing purposes).
     *
     * @return SpanInterface|null
     */
    public static function getLoginSpan(): ?SpanInterface
    {
        return self::$loginSpan;
    }

    /**
     * Returns the active logout span (for testing purposes).
     *
     * @return SpanInterface|null
     */
    public static function getLogoutSpan(): ?SpanInterface
    {
        return self::$logoutSpan;
    }
}
