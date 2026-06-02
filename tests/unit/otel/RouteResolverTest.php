<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\RouteResolver;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanContextInterface;
use OpenTelemetry\API\Trace\SpanContext;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\Context\ScopeInterface;
use Throwable;
use yii\base\ActionEvent;

/**
 * A test double that extends the abstract Span class so it inherits
 * the real activate()/storeInContext() methods for OTEL context integration.
 * Tracks updateName() calls for assertion.
 */
class TestSpan extends Span
{
    public ?string $updatedName = null;
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
        return $this;
    }

    public function setAttributes(iterable $attributes): SpanInterface
    {
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

    public function recordException(Throwable $exception, iterable $attributes = []): SpanInterface
    {
        return $this;
    }

    public function updateName(string $name): SpanInterface
    {
        $this->updatedName = $name;
        return $this;
    }

    public function setStatus(string $code, ?string $description = null): SpanInterface
    {
        return $this;
    }

    public function end(?int $endEpochNanos = null): void
    {
    }
}

/**
 * Unit tests for RouteResolver::handleAfterAction().
 *
 * Validates that the root span is renamed to the correct Yii2 route format
 * for web requests (with and without modules), console commands, and 404s.
 *
 * Requirements: 1.1, 1.2, 1.3, 1.4, 1.5
 */
class RouteResolverTest extends \PHPUnit\Framework\TestCase
{
    private ?ScopeInterface $scope = null;
    private ?TestSpan $testSpan = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->testSpan = new TestSpan();
        $this->scope = $this->testSpan->activate();
    }

    protected function tearDown(): void
    {
        if ($this->scope !== null) {
            $this->scope->detach();
            $this->scope = null;
        }
        $this->testSpan = null;
        parent::tearDown();
    }

    /**
     * Test: Web route with module prefix produces correct span name.
     *
     * Simulates a backend request to `academics/exams/index` where the
     * controller lives inside the `academics` module.
     *
     * Validates: Requirements 1.1, 1.2
     */
    public function testWebRouteWithModulePrefix(): void
    {
        // Build mock objects: Module -> Controller -> Action
        $module = $this->createMock(\yii\base\Module::class);
        $module->id = 'academics';

        $controller = $this->createMock(\yii\web\Controller::class);
        $controller->id = 'exams';
        $controller->module = $module;

        $action = $this->createMock(\yii\base\Action::class);
        $action->id = 'index';
        $action->controller = $controller;

        // Set up a web application mock
        $app = $this->createMock(\yii\web\Application::class);
        $app->id = 'backend';
        \Yii::$app = $app;

        $event = new ActionEvent($action);
        RouteResolver::handleAfterAction($event);

        $this->assertSame('academics/exams/index', $this->testSpan->updatedName);
    }

    /**
     * Test: Web route without module produces `{controllerId}/{actionId}`.
     *
     * Simulates an admin request to `tenants/view` where the controller
     * is directly under the application (no module).
     *
     * Validates: Requirements 1.1, 1.3
     */
    public function testWebRouteWithoutModule(): void
    {
        // When the controller's module IS the application itself, moduleId should be null
        $app = $this->createMock(\yii\web\Application::class);
        $app->id = 'admin';
        \Yii::$app = $app;

        $controller = $this->createMock(\yii\web\Controller::class);
        $controller->id = 'tenants';
        $controller->module = $app; // module is the app itself — no real module

        $action = $this->createMock(\yii\base\Action::class);
        $action->id = 'view';
        $action->controller = $controller;

        $event = new ActionEvent($action);
        RouteResolver::handleAfterAction($event);

        $this->assertSame('tenants/view', $this->testSpan->updatedName);
    }

    /**
     * Test: Console route produces `console/{route}`.
     *
     * Simulates a console command `migrate/up`.
     *
     * Validates: Requirements 1.4
     */
    public function testConsoleRoute(): void
    {
        $app = $this->createMock(\yii\console\Application::class);
        $app->id = 'console';
        $app->requestedRoute = 'migrate/up';
        \Yii::$app = $app;

        $controller = $this->createMock(\yii\console\Controller::class);
        $controller->id = 'migrate';
        $controller->module = $app;

        $action = $this->createMock(\yii\base\Action::class);
        $action->id = 'up';
        $action->controller = $controller;

        $event = new ActionEvent($action);
        RouteResolver::handleAfterAction($event);

        $this->assertSame('console/migrate/up', $this->testSpan->updatedName);
    }

    /**
     * Test: 404 (no action dispatched — null action) retains original span name.
     *
     * When no action is dispatched (e.g., 404 before routing), the handler
     * should return early without calling updateName().
     *
     * Validates: Requirements 1.5
     */
    public function testNullActionRetainsOriginalSpanName(): void
    {
        $app = $this->createMock(\yii\web\Application::class);
        $app->id = 'backend';
        \Yii::$app = $app;

        // ActionEvent with null action (simulating 404 before dispatch)
        $event = new ActionEvent(null);

        RouteResolver::handleAfterAction($event);

        // updateName should NOT have been called — updatedName stays null
        $this->assertNull($this->testSpan->updatedName);
    }
}
