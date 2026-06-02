# Implementation Plan: danvick/yii2-opentelemetry Package

## Overview

Extract the existing `common/components/otel/` instrumentation into a standalone Composer package at `../yii2-opentelemetry/`, adding root span lifecycle management, `SpanAttributeProviderInterface`, toggleable modules, and configurable DB connections. Then integrate the package back into Skoolite, removing the old code and `opentelemetry-auto-yii` dependency.

## Tasks

- [x] 1. Scaffold package structure
  - [x] 1.1 Create package directory and composer.json
    - Create `../yii2-opentelemetry/` directory
    - Create `../yii2-opentelemetry/composer.json` with `danvick/yii2-opentelemetry` name, `psr-4` autoload for `danvick\yii2\otel\` → `src/`, require `yiisoft/yii2`, `open-telemetry/sdk`, suggest `ext-opentelemetry`, `yiisoft/yii2-queue`, `yiisoft/yii2-redis`
    - Create `../yii2-opentelemetry/src/` and `../yii2-opentelemetry/tests/unit/` directories
    - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.5, 1.6, 1.7, 1.8_

  - [x] 1.2 Create PHPUnit configuration for the package
    - Create `../yii2-opentelemetry/phpunit.xml` pointing to `tests/unit/`
    - Create `../yii2-opentelemetry/tests/bootstrap.php` requiring Composer autoloader
    - _Requirements: 1.1_

- [x] 2. Implement core helpers and interfaces
  - [x] 2.1 Create OtelHelpers
    - Create `../yii2-opentelemetry/src/OtelHelpers.php` under `danvick\yii2\otel` namespace
    - Port all static methods from `common/components/otel/OtelHelpers.php`: `buildRouteName`, `buildConsoleRouteName`, `extractSqlVerb`, `sanitizeSql`, `extractDbNameFromDsn`, `resolveConnectionName`, `mapYiiLogLevel`, `shortClassName`, `hasActiveSpan`
    - Update `resolveConnectionName` to accept a `$knownConnections` array parameter instead of hardcoded connection names
    - _Requirements: 5.1, 5.2, 5.3, 6.2, 6.4, 6.6, 13.1, 13.2, 13.3, 14.1, 14.2, 15.1, 15.2, 15.3, 16.1, 16.2, 17.3_

  - [ ]* 2.2 Write property tests for OtelHelpers
    - **Property 1: Route-to-span-name produces correct format**
    - **Validates: Requirements 5.1, 5.2, 5.3**
    - **Property 2: SQL verb extraction and DB span naming**
    - **Validates: Requirements 6.2, 15.1, 15.2, 15.3**
    - **Property 3: SQL parameter sanitization round-trip safety**
    - **Validates: Requirements 6.6, 13.1, 13.2**
    - **Property 4: DSN database name extraction**
    - **Validates: Requirements 6.4, 14.1**
    - **Property 5: Short class name extraction and job span naming**
    - **Validates: Requirements 9.2, 16.1, 16.3**
    - **Property 9: Log level mapping correctness**
    - **Validates: Requirements 10.3**
    - Create `../yii2-opentelemetry/tests/unit/OtelHelpersTest.php`

  - [x] 2.3 Create SpanAttributeProviderInterface
    - Create `../yii2-opentelemetry/src/SpanAttributeProviderInterface.php` under `danvick\yii2\otel` namespace
    - Define `getAttributes(): array` method returning associative array of span attribute key-value pairs
    - _Requirements: 8.1_

- [x] 3. Implement root span lifecycle (OtelBootstrap)
  - [x] 3.1 Create OtelBootstrap with root span management
    - Create `../yii2-opentelemetry/src/OtelBootstrap.php` under `danvick\yii2\otel` namespace
    - Implement `yii\base\BootstrapInterface`
    - Add configuration properties: `serviceName`, `instrumentDb`, `instrumentCache`, `instrumentQueue`, `logBridge`, `dbConnections`, `spanAttributeProviders`
    - Implement gate checks: `extension_loaded('opentelemetry')` and `OTEL_SDK_DISABLED` env var
    - Obtain TracerProvider/LoggerProvider from `Globals::tracerProvider()` / `Globals::loggerProvider()`
    - Create tracer with resolved service name (property → `OTEL_SERVICE_NAME` fallback)
    - Register `EVENT_BEFORE_REQUEST` handler: create root span `HTTP {METHOD}`, set HTTP semantic attributes (`http.method`, `http.url`, `http.target`, `http.scheme`, `http.host`), activate scope, invoke `SpanAttributeProviderInterface` providers
    - Register `EVENT_AFTER_REQUEST` handler: set `http.status_code`, end root span, detach scope
    - Register `EVENT_BEFORE_ACTION` handler for console apps: create root span `console/{route}`, activate scope
    - Register `EVENT_AFTER_ACTION` handler for console apps: end root span, detach scope
    - Register shutdown function for unhandled exceptions: record exception, set ERROR status, end span, detach scope
    - Conditionally register sub-components based on toggle properties
    - _Requirements: 2.1, 2.2, 2.3, 2.4, 2.5, 2.6, 2.7, 2.8, 3.1, 3.2, 3.3, 4.1, 4.2, 4.3, 4.4, 8.2, 8.3, 8.4, 8.5, 11.1, 11.2, 11.3, 11.4, 12.1, 12.2, 12.3, 12.4, 12.5_

  - [ ]* 3.2 Write unit tests for OtelBootstrap
    - **Property 12: HTTP semantic attributes on root span**
    - **Validates: Requirements 2.6**
    - **Property 13: Span attribute providers invocation**
    - **Validates: Requirements 8.3**
    - Test graceful degradation: extension not loaded → no exceptions, `OTEL_SDK_DISABLED=true` → no spans
    - Test toggle behavior: each `instrument*` flag disables its module
    - Test service name resolution: property takes precedence over env var
    - Test SpanAttributeProvider error handling: provider throws → logged, others still invoked
    - Create `../yii2-opentelemetry/tests/unit/OtelBootstrapTest.php`

- [x] 4. Checkpoint - Verify core components
  - Ensure all tests pass, ask the user if questions arise.

- [x] 5. Implement DB instrumentation
  - [x] 5.1 Create DbInstrumentation and InstrumentedCommand
    - Create `../yii2-opentelemetry/src/DbInstrumentation.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/DbInstrumentation.php`, updating `resolveConnectionName` to use configurable `$knownConnections` array
    - Add static `setKnownConnections(array $connections)` method called by OtelBootstrap
    - Create `../yii2-opentelemetry/src/InstrumentedCommand.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/InstrumentedCommand.php`, updating namespace references
    - Span attributes: `db.system=mysql`, `db.name` (from DSN), `db.connection_name`, `db.statement` (sanitized)
    - Include active span guard (`OtelHelpers::hasActiveSpan()`) to skip span creation when no parent exists
    - _Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 17.1_

  - [ ]* 5.2 Write unit tests for DbInstrumentation
    - Test `wrapWithSpan` creates child span with correct attributes
    - Test active span guard skips span creation when no parent span
    - Test exception recording on query failure
    - Test `EVENT_AFTER_OPEN` swaps `commandClass` to `InstrumentedCommand`
    - Create `../yii2-opentelemetry/tests/unit/DbInstrumentationTest.php`
    - _Requirements: 6.1, 6.7, 17.1_

- [x] 6. Implement cache instrumentation
  - [x] 6.1 Create InstrumentedCache
    - Create `../yii2-opentelemetry/src/InstrumentedCache.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/InstrumentedCache.php`, updating namespace references
    - Override `getValue`, `setValue`, `deleteValue`, `flushValues` with OTEL child spans
    - Span naming: `CACHE GET`, `CACHE SET`, `CACHE DELETE`, `CACHE FLUSH`
    - Attributes: `db.system=redis`, `cache.key` (except flush), `cache.hit` (GET only)
    - Include active span guard to skip span creation when no parent exists
    - _Requirements: 7.1, 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 17.2_

  - [ ]* 6.2 Write unit tests for InstrumentedCache
    - **Property 6: Cache operation span naming**
    - **Validates: Requirements 7.1, 7.3**
    - **Property 7: Cache hit/miss attribute correctness**
    - **Validates: Requirements 7.2**
    - **Property 8: Cache span required attributes invariant**
    - **Validates: Requirements 7.4, 7.5**
    - Test active span guard skips span creation when no parent span
    - Create `../yii2-opentelemetry/tests/unit/InstrumentedCacheTest.php`

- [x] 7. Implement queue instrumentation
  - [x] 7.1 Create QueueInstrumentation
    - Create `../yii2-opentelemetry/src/QueueInstrumentation.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/QueueInstrumentation.php`, updating namespace references
    - `EVENT_BEFORE_PUSH`: capture `trace_id` + `span_id` into `_otelTraceContext` on job
    - `EVENT_BEFORE_EXEC`: create root span `JOB {shortClassName}` with optional SpanLink from `_otelTraceContext`
    - `EVENT_AFTER_EXEC`: end job span
    - `EVENT_AFTER_ERROR`: record exception, set ERROR status, end span
    - Remove hardcoded `MultiTenantJob` tenant attribute logic (now handled by SpanAttributeProviderInterface)
    - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7_

  - [ ]* 7.2 Write unit tests for QueueInstrumentation
    - **Property 11: Queue trace context round-trip**
    - **Validates: Requirements 9.1, 9.3**
    - Test job without `_otelTraceContext` creates span without link
    - Test error handling records exception and sets ERROR status
    - Create `../yii2-opentelemetry/tests/unit/QueueInstrumentationTest.php`

- [x] 8. Implement log bridge
  - [x] 8.1 Create OtelLogTarget
    - Create `../yii2-opentelemetry/src/OtelLogTarget.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/OtelLogTarget.php`, updating namespace references
    - Remove app-specific `resolveTenantId` logic (tenant context now comes from SpanAttributeProviderInterface on root span)
    - Map Yii2 log levels to OTEL severity: ERROR→ERROR, WARNING→WARN, INFO→INFO, TRACE→DEBUG, PROFILE→DEBUG
    - Attach `trace_id`, `span_id` from active span context, `log.category` from Yii2 category
    - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 10.6_

  - [ ]* 8.2 Write unit tests for OtelLogTarget
    - **Property 10: Log record trace context and category attributes**
    - **Validates: Requirements 10.2, 10.4**
    - Test log records contain trace context when active span exists
    - Test log records omit trace context when no active span
    - Create `../yii2-opentelemetry/tests/unit/OtelLogTargetTest.php`

- [x] 9. Implement SpanRenamer
  - [x] 9.1 Create SpanRenamer
    - Create `../yii2-opentelemetry/src/SpanRenamer.php` under `danvick\yii2\otel` namespace
    - Port from `common/components/otel/SpanRenamer.php`, updating namespace references
    - Register via `Application::EVENT_AFTER_ACTION`
    - Web with module: `{moduleId}/{controllerId}/{actionId}`
    - Web without module: `{controllerId}/{actionId}`
    - Console: `console/{route}`
    - Retain initial span name if no action dispatched (404)
    - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5_

  - [ ]* 9.2 Write unit tests for SpanRenamer
    - Test span rename with module, without module, and console routes
    - Test 404 scenario retains initial span name
    - Create `../yii2-opentelemetry/tests/unit/SpanRenamerTest.php`

- [x] 10. Checkpoint - Verify all package components
  - Ensure all tests pass, ask the user if questions arise.

- [x] 11. Integrate package into Skoolite
  - [x] 11.1 Update Skoolite composer.json
    - Add path repository entry: `{"type": "path", "url": "../yii2-opentelemetry"}` to `repositories` array
    - Add `"danvick/yii2-opentelemetry": "@dev"` to `require`
    - Remove `"open-telemetry/opentelemetry-auto-yii": "^0.1.0"` from `require`
    - _Requirements: 1.7, 1.8_

  - [x] 11.2 Update common/config/main.php bootstrap
    - Replace `OtelBootstrap::class` (from `common\components\otel`) with inline config array using `\danvick\yii2\otel\OtelBootstrap::class`
    - Configure: `serviceName`, `instrumentDb => true`, `instrumentCache => true`, `instrumentQueue => true`, `logBridge => false`, `dbConnections => ['db', 'masterDb', 'statsDb']`, `spanAttributeProviders => [\common\components\otel\TenantSpanAttributes::class]`
    - Update use statement at top of file
    - _Requirements: 2.1, 3.1, 6.8, 6.9, 8.2, 12.1_

  - [x] 11.3 Refactor TenantSpanAttributes to implement SpanAttributeProviderInterface
    - Update `common/components/otel/TenantSpanAttributes.php` to implement `danvick\yii2\otel\SpanAttributeProviderInterface`
    - Replace the static `register()` + event handler pattern with `getAttributes(): array` method
    - Return `['tenant.id' => ..., 'tenant.name' => ..., 'tenant.db_name' => ...]` from resolved tenant
    - Return empty array when no tenant can be resolved
    - _Requirements: 8.1, 8.2, 8.3, 8.4_

  - [x] 11.4 Remove old common/components/otel files that are now in the package
    - Delete `common/components/otel/OtelBootstrap.php`
    - Delete `common/components/otel/OtelHelpers.php`
    - Delete `common/components/otel/SpanRenamer.php`
    - Delete `common/components/otel/DbInstrumentation.php`
    - Delete `common/components/otel/InstrumentedCommand.php`
    - Delete `common/components/otel/InstrumentedCache.php`
    - Delete `common/components/otel/QueueInstrumentation.php`
    - Delete `common/components/otel/OtelLogTarget.php`
    - Keep `common/components/otel/TenantSpanAttributes.php` (refactored in 11.3)
    - Keep `common/components/otel/YiiOtelLogWriter.php` if it exists (used by OtelBootstrap internally — move to package if needed)
    - _Requirements: 1.1, 1.8_

  - [x] 11.5 Update existing tests to use new namespace
    - Update test files in `common/tests/unit/otel/` to import from `danvick\yii2\otel\` namespace instead of `common\components\otel\`
    - Update `OtelHelpersTest.php`, `SpanRenamerTest.php`, `DbInstrumentationTest.php`, `CacheInstrumentationTest.php`, `QueueInstrumentationTest.php`, `OtelLogTargetTest.php`, `OtelBootstrapTest.php`
    - Keep `TenantSpanAttributesTest.php` pointing to `common\components\otel\TenantSpanAttributes`
    - _Requirements: 1.1_

- [x] 12. Final checkpoint - Ensure all tests pass
  - Ensure all tests pass, ask the user if questions arise.

- [x] 13. Implement view rendering instrumentation
  - [x] 13.1 Create ViewInstrumentation
    - Create `yii2-opentelemetry/src/ViewInstrumentation.php` under `danvick\yii2\otel` namespace
    - Hook into `View::EVENT_BEFORE_RENDER` and `View::EVENT_AFTER_RENDER`
    - Use `SplStack` to handle nested view rendering (layout → content → partials)
    - Span naming: `VIEW {shortPath}` where shortPath is relative to the app's viewPath
    - Attributes: `view.file` (full path)
    - Include active span guard
    - _Requirements: 18.1, 18.2, 18.3, 18.4, 18.5, 18.6, 18.7_

  - [ ]* 13.2 Write unit tests for ViewInstrumentation
    - **Property 14: View span naming and attributes**
    - **Property 15: Nested view span stacking**
    - Test nested view rendering produces correct parent-child hierarchy
    - Test exception during render records error and cleans up stack
    - Create `yii2-opentelemetry/tests/unit/ViewInstrumentationTest.php`

- [x] 14. Implement ActiveRecord instrumentation
  - [x] 14.1 Create ArInstrumentation
    - Create `yii2-opentelemetry/src/ArInstrumentation.php` under `danvick\yii2\otel` namespace
    - Hook into `ActiveRecord::EVENT_BEFORE_INSERT`/`EVENT_AFTER_INSERT`, `EVENT_BEFORE_UPDATE`/`EVENT_AFTER_UPDATE`, `EVENT_BEFORE_DELETE`/`EVENT_AFTER_DELETE` for save/delete spans
    - For find operations, hook into `ActiveRecord::EVENT_AFTER_FIND` or wrap ActiveQuery
    - Span naming: `AR FIND {Model}`, `AR SAVE {Model}`, `AR DELETE {Model}`
    - Attributes: `ar.model` (short class name), `ar.operation` (find/save/delete), `ar.is_new` (save only)
    - DB query spans nest under AR spans via OTEL context propagation
    - Include active span guard
    - _Requirements: 19.1, 19.2, 19.3, 19.4, 19.5, 19.6, 19.7, 19.8, 19.9, 19.10_

  - [ ]* 14.2 Write unit tests for ArInstrumentation
    - **Property 16: AR operation span naming and attributes**
    - **Property 17: AR save is_new attribute correctness**
    - Test find/save/delete create correct spans
    - Test DB spans nest under AR spans
    - Create `yii2-opentelemetry/tests/unit/ArInstrumentationTest.php`

- [x] 15. Implement HTTP client instrumentation
  - [x] 15.1 Create HttpClientInstrumentation
    - Create `yii2-opentelemetry/src/HttpClientInstrumentation.php` under `danvick\yii2\otel` namespace
    - Hook into `yii\httpclient\Client::EVENT_BEFORE_SEND` and `EVENT_AFTER_SEND`
    - Span naming: `HTTP {METHOD} {host}` (host extracted via `parse_url()`)
    - Span kind: `KIND_CLIENT`
    - Attributes: `http.method`, `http.url`, `http.host`, `http.status_code` (set after response)
    - Include active span guard
    - _Requirements: 20.1, 20.2, 20.3, 20.4, 20.5, 20.6, 20.7_

  - [ ]* 15.2 Write unit tests for HttpClientInstrumentation
    - **Property 18: HTTP client outbound span naming and attributes**
    - Test transport error records exception on span
    - Test URL without host falls back gracefully
    - Create `yii2-opentelemetry/tests/unit/HttpClientInstrumentationTest.php`

- [x] 16. Implement authentication instrumentation
  - [x] 16.1 Create AuthInstrumentation
    - Create `yii2-opentelemetry/src/AuthInstrumentation.php` under `danvick\yii2\otel` namespace
    - Hook into `User::EVENT_BEFORE_LOGIN`/`EVENT_AFTER_LOGIN` and `EVENT_BEFORE_LOGOUT`/`EVENT_AFTER_LOGOUT`
    - Span naming: `AUTH LOGIN`, `AUTH LOGOUT`
    - Attributes: `auth.user_id` (when identity available), `auth.success` (set after login)
    - Include active span guard
    - _Requirements: 21.1, 21.2, 21.3, 21.4, 21.5, 21.6, 21.7_

  - [ ]* 16.2 Write unit tests for AuthInstrumentation
    - Test login/logout create correct spans
    - Test auth.user_id set when identity available
    - Create `yii2-opentelemetry/tests/unit/AuthInstrumentationTest.php`

- [x] 17. Implement mail instrumentation
  - [x] 17.1 Create MailInstrumentation
    - Create `yii2-opentelemetry/src/MailInstrumentation.php` under `danvick\yii2\otel` namespace
    - Hook into `BaseMailer::EVENT_BEFORE_SEND` and `EVENT_AFTER_SEND`
    - Span naming: `MAIL SEND`
    - Attributes: `mail.to` (recipients), `mail.subject`, `mail.success` (set after send)
    - Include active span guard
    - _Requirements: 22.1, 22.2, 22.3, 22.4, 22.5, 22.6, 22.7_

  - [ ]* 17.2 Write unit tests for MailInstrumentation
    - **Property 19: Mail span required attributes**
    - Test send failure sets mail.success=false
    - Test exception during send records error on span
    - Create `yii2-opentelemetry/tests/unit/MailInstrumentationTest.php`

- [x] 18. Update OtelBootstrap with new toggle properties
  - [x] 18.1 Add new config properties and conditional registration
    - Add `instrumentViews`, `instrumentAr`, `instrumentHttpClient`, `instrumentAuth`, `instrumentMail` boolean properties (all default `false`) to `OtelBootstrap`
    - Add conditional registration calls in `registerInstrumentation()` for each new module
    - _Requirements: 12.1, 12.6, 12.7, 12.8, 12.9, 12.10_

- [x] 19. Update Skoolite bootstrap config with new toggles
  - [x] 19.1 Update common/config/main.php
    - Add `instrumentViews => false`, `instrumentAr => false`, `instrumentHttpClient => false`, `instrumentAuth => false`, `instrumentMail => false` to the OtelBootstrap config array
    - _Requirements: 12.1_

- [x] 20. Final checkpoint - Verify new instrumentation modules
  - Ensure all new modules work correctly, ask the user if questions arise.

## Notes

- Tasks marked with `*` are optional and can be skipped for faster MVP
- Each task references specific requirements for traceability
- Checkpoints ensure incremental validation
- Property tests validate universal correctness properties from the design document
- The package is created at `../yii2-opentelemetry/` as a sibling directory to the Skoolite project
- `YiiOtelLogWriter` should be checked during task 11.4 — if it exists in `common/components/otel/`, it should be moved to the package as well
