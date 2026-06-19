<?php

namespace common\tests\unit\otel;

use danvick\yii2\otel\ArInstrumentation;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\Context\ScopeInterface;
use OpenTelemetry\SDK\Trace\SpanExporter\InMemoryExporter;
use OpenTelemetry\SDK\Trace\SpanProcessor\SimpleSpanProcessor;
use OpenTelemetry\SDK\Trace\TracerProvider;
use PHPUnit\Framework\TestCase;
use yii\base\Event;
use yii\base\Model;
use yii\base\ModelEvent;

/**
 * A plain sender object for AR instrumentation events (the handlers only read
 * get_class($event->sender), so a Model stub is sufficient).
 */
class DummyAr extends Model
{
}

/**
 * Regression tests for ArInstrumentation scope/span lifecycle.
 *
 * Guards against the scope leak where AR save spans activated in a before-hook
 * were never detached, corrupting the OpenTelemetry context LIFO and crashing
 * the root-span teardown ("another scope should have been detached first").
 */
class ArInstrumentationTest extends TestCase
{
    private InMemoryExporter $exporter;
    private ?ScopeInterface $rootScope = null;
    private $rootSpan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->exporter = new InMemoryExporter();
        $tracer = (new TracerProvider(new SimpleSpanProcessor($this->exporter)))->getTracer('test');
        ArInstrumentation::register($tracer);

        // An active root span so OtelHelpers::hasActiveSpan() is true.
        $this->rootSpan = $tracer->spanBuilder('root')->startSpan();
        $this->rootScope = $this->rootSpan->activate();
    }

    protected function tearDown(): void
    {
        // Best-effort cleanup so one test can't disturb another.
        ArInstrumentation::reset();
        $this->rootScope?->detach();
        $this->rootScope = null;
        $this->rootSpan?->end();
        parent::tearDown();
    }

    private function fireBeforeUpdate(): void
    {
        ArInstrumentation::handleBeforeUpdate(new ModelEvent(['sender' => new DummyAr()]));
    }

    private function fireAfterUpdate(): void
    {
        ArInstrumentation::handleAfterSave(new Event(['sender' => new DummyAr()]));
    }

    public function testBalancedSaveLeavesStackEmptyAndRestoresRootContext(): void
    {
        $rootContext = Span::getCurrent()->getContext();

        $this->fireBeforeUpdate();
        $this->assertSame(1, ArInstrumentation::getSpanStack()->count(), 'before-hook should push a span');

        $this->fireAfterUpdate();
        $this->assertTrue(ArInstrumentation::getSpanStack()->isEmpty(), 'after-hook should pop the span');

        // The active context must be restored to the root span (scope detached).
        $this->assertSame(
            $rootContext->getSpanId(),
            Span::getCurrent()->getContext()->getSpanId(),
            'after a balanced save the current span must be the root again'
        );
    }

    public function testManySequentialSavesDoNotAccumulate(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->fireBeforeUpdate();
            $this->fireAfterUpdate();
        }

        $this->assertTrue(ArInstrumentation::getSpanStack()->isEmpty(), 'sequential saves must not leak spans');
        // 25 AR spans should have been exported (root is still open).
        $this->assertCount(25, $this->exporter->getSpans());
    }

    public function testResetDrainsStrandedSpans(): void
    {
        // Simulate stranding: before-hooks fire without their matching after-hooks
        // (e.g. a vetoed/aborted save or nested operation).
        $this->fireBeforeUpdate();
        $this->fireBeforeUpdate();
        $this->assertSame(2, ArInstrumentation::getSpanStack()->count());

        ArInstrumentation::reset();

        $this->assertTrue(ArInstrumentation::getSpanStack()->isEmpty(), 'reset must drain stranded spans');

        // After draining, detaching the root scope must not throw — this is the exact
        // teardown that previously crashed with "another scope should have been detached first".
        $this->rootScope->detach();
        $this->rootScope = null;
        $this->addToAssertionCount(1);
    }

    public function testResetIsSafeWhenStackEmpty(): void
    {
        ArInstrumentation::reset();
        ArInstrumentation::reset();
        $this->assertTrue(ArInstrumentation::getSpanStack()->isEmpty());
    }
}
