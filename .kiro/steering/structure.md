# Project Structure

```
src/                          # All source code (PSR-4 root: danvick\yii2\otel\)
├── OtelBootstrap.php         # Entry point — implements BootstrapInterface, registers all instrumentation
├── OtelHelpers.php           # Pure static helper functions (SQL sanitization, route building, log level mapping, etc.)
├── RouteResolver.php         # Renames root span to resolved Yii2 route after action dispatch
├── SpanAttributeProviderInterface.php  # Extension point for custom span attributes
├── DbInstrumentation.php     # DB query spans via Connection::EVENT_AFTER_OPEN
├── InstrumentedCommand.php   # Wraps yii\db\Command to create spans around execute/query
├── InstrumentedCache.php     # Extends yii\redis\Cache with span-wrapped cache operations
├── QueueInstrumentation.php  # Queue job spans with trace context linking via SpanLink
├── ViewInstrumentation.php   # View/layout rendering spans
├── ArInstrumentation.php     # ActiveRecord save/delete spans
├── HttpClientInstrumentation.php  # Outbound HTTP request spans
├── AuthInstrumentation.php   # Login/logout spans
├── MailInstrumentation.php   # Mail sending spans
└── OtelLogTarget.php         # Bridges Yii2 logs to OTEL LoggerProvider

tests/
├── bootstrap.php             # Test bootstrap (autoloader)
└── unit/                     # PHPUnit unit tests
```

## Architecture Patterns

- **OtelBootstrap** is the single entry point. It reads config flags and conditionally registers each instrumentation module.
- **Instrumentation classes** are static and register themselves via `Yii2 Event::on()` class-level events. They follow a consistent pattern:
  1. `register(TracerInterface $tracer)` — stores the tracer and attaches event handlers
  2. Event handlers create spans with `spanBuilder()`, set attributes, call `startSpan()` / `activate()` / `end()`
  3. Exceptions are caught, recorded on the span, status set to ERROR, then re-thrown
- **OtelHelpers** contains pure stateless functions — no side effects, easy to unit test
- **InstrumentedCache** uses class extension (not events) because `yii\redis\Cache` doesn't fire events for get/set/delete
- **Guard pattern**: instrumentation methods check `OtelHelpers::hasActiveSpan()` before creating child spans to avoid orphaned root spans
- **Span naming convention**: `{TYPE} {OPERATION}` — e.g., `DB SELECT`, `CACHE GET`, `JOB SendEmailJob`, `AR SAVE Tenant`, `AUTH LOGIN`
