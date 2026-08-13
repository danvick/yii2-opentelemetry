<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\OtelBootstrap;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use yii\base\ActionEvent;

/**
 * Regression tests for the console root-span lifecycle.
 *
 * Guards against the scope leak seen with `yii migrate --tenantId=all`, where the
 * controller calls runAction() from its own beforeAction(): the nested
 * EVENT_BEFORE_ACTION overwrote the outer root span, destroying its still-attached
 * scope ("Scope: missing call to Scope::detach()") and aborting the command.
 */
class ConsoleRootSpanTest extends TestCase
{
    private InMemoryExporter $exporter;
    private OtelBootstrap $bootstrap;

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->createMock(\yii\console\Application::class);
        $app->id = 'console';
        $app->requestedRoute = 'migrate/up';
        \Yii::$app = $app;

        $this->exporter = new InMemoryExporter();
        $tracer = (new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test');

        $this->bootstrap = new OtelBootstrap();
        $tracerProperty = new ReflectionProperty(OtelBootstrap::class, 'tracer');
        $tracerProperty->setAccessible(true);
        $tracerProperty->setValue($this->bootstrap, $tracer);
    }

    protected function tearDown(): void
    {
        // Drain anything a failing assertion left open, so one test cannot strand
        // an attached scope and cascade into the next.
        $this->bootstrap->handleShutdown();
        \Yii::$app = null;
        parent::tearDown();
    }

    private function fireBefore(): void
    {
        $this->bootstrap->handleBeforeConsoleAction($this->createMock(ActionEvent::class));
    }

    private function fireAfter(): void
    {
        $this->bootstrap->handleAfterConsoleAction($this->createMock(ActionEvent::class));
    }

    private function currentSpanId(): string
    {
        return Span::getCurrent()->getContext()->getSpanId();
    }

    public function testNestedConsoleActionsEndBothSpansAndRestoreContext(): void
    {
        $outerContextSpanId = $this->currentSpanId();

        $this->fireBefore();
        $outerSpanId = $this->currentSpanId();

        $this->fireBefore();
        $innerSpanId = $this->currentSpanId();
        $this->assertNotSame($outerSpanId, $innerSpanId, 'nested action should start its own span');

        $this->fireAfter();
        $this->assertSame($outerSpanId, $this->currentSpanId(), 'ending the nested action must restore the outer span');

        $this->fireAfter();
        $this->assertSame($outerContextSpanId, $this->currentSpanId(), 'the outer scope must be detached too');

        // Both spans end — under the bug the outer root was overwritten and never ended.
        $this->assertCount(2, $this->exporter->getSpans());
    }

    public function testManyNestedActionsUnwindInOrder(): void
    {
        // `--tenantId=all` re-enters runAction() once per tenant.
        $this->fireBefore();
        $outerSpanId = $this->currentSpanId();

        for ($tenant = 0; $tenant < 5; $tenant++) {
            $this->fireBefore();
            $this->fireAfter();
            $this->assertSame($outerSpanId, $this->currentSpanId(), 'each tenant must unwind back to the outer span');
        }

        $this->fireAfter();
        $this->assertCount(6, $this->exporter->getSpans());
    }

    public function testShutdownDrainsRootsLeftOpenByAVetoedAction(): void
    {
        // beforeAction() returning false skips EVENT_AFTER_ACTION entirely.
        $contextSpanId = $this->currentSpanId();
        $this->fireBefore();
        $this->fireBefore();

        $this->bootstrap->handleShutdown();

        $this->assertSame($contextSpanId, $this->currentSpanId(), 'shutdown must detach every stranded scope');
        $this->assertCount(2, $this->exporter->getSpans());
    }
}
