# Requirements Document

## Introduction

A standalone Composer package (`danvick/yii2-opentelemetry`) providing complete OpenTelemetry instrumentation for Yii2 applications. The package replaces the broken `open-telemetry/opentelemetry-auto-yii` package by owning the root span lifecycle, creating properly nested child spans for DB, cache, and queue operations, and bridging Yii2 logs to the OTEL log exporter. The package is framework-level (not app-specific) and uses a `SpanAttributeProviderInterface` for extensibility.

The core problem being solved: child spans (DB queries, cache operations) appear as orphaned top-level spans in observability backends (e.g., SigNoz) instead of nesting under request root spans. This happens because `opentelemetry-auto-yii` does not properly manage span context propagation. By owning the root span from `EVENT_BEFORE_REQUEST` through `EVENT_AFTER_REQUEST`, the package ensures all child spans created during request processing are correctly parented.

## Glossary

- **Package**: The `danvick/yii2-opentelemetry` Composer package distributed via Packagist.
- **OtelBootstrap**: The central bootstrap component implementing `yii\base\BootstrapInterface` that initializes all instrumentation and manages the root span lifecycle.
- **Root_Span**: The top-level span for an HTTP request or console command, created and ended by OtelBootstrap. All child spans nest under the Root_Span.
- **Child_Span**: A span nested under the Root_Span representing a sub-operation (DB query, cache call, queue push).
- **Span_Attribute**: A key-value pair attached to a span providing contextual metadata.
- **Span_Link**: An OpenTelemetry link connecting a span in one trace to a span in another trace.
- **Trace_Context**: The combination of `trace_id` and `span_id` that uniquely identifies a span within a distributed trace.
- **SpanAttributeProviderInterface**: An interface exposed by the Package allowing consuming applications to inject custom span attributes (e.g., tenant context) into the Root_Span.
- **SpanRenamer**: The component that renames the Root_Span to the resolved Yii2 route after action dispatch.
- **InstrumentedCommand**: A subclass of `yii\db\Command` that wraps query execution with OTEL child spans.
- **InstrumentedCache**: A subclass of `yii\redis\Cache` that wraps cache operations with OTEL child spans.
- **Log_Bridge**: The `OtelLogTarget` component that forwards Yii2 log messages to the OTEL log exporter.
- **TracerProvider**: The OpenTelemetry SDK component that creates Tracer instances, configured via standard OTEL environment variables.
- **Globals_API**: The `OpenTelemetry\API\Globals` class used to obtain the auto-configured TracerProvider and LoggerProvider.
- **OTEL_SDK**: The OpenTelemetry PHP SDK (`open-telemetry/sdk`) and its auto-configuration, driven by standard environment variables.
- **Connection_Name**: The Yii2 application component ID for a database connection (e.g., `db`, `masterDb`).
- **ViewInstrumentation**: The component that creates child spans for view rendering operations, hooking into `View::EVENT_BEFORE_RENDER` and `View::EVENT_AFTER_RENDER`.
- **ArInstrumentation**: The component that creates child spans for ActiveRecord lifecycle operations (find, save, delete), hooking into ActiveRecord events.
- **HttpClientInstrumentation**: The component that creates child spans for outbound HTTP requests made via `yii\httpclient\Client`, hooking into `EVENT_BEFORE_SEND` and `EVENT_AFTER_SEND`.
- **AuthInstrumentation**: The component that creates child spans for authentication events (login, logout), hooking into `yii\web\User` events.
- **MailInstrumentation**: The component that creates child spans for mail sending operations, hooking into `yii\mail\BaseMailer` events.

## Requirements

### Requirement 1: Package Structure and Distribution

**User Story:** As a developer, I want to install `danvick/yii2-opentelemetry` via Composer, so that I can add OpenTelemetry instrumentation to any Yii2 application without depending on the broken `opentelemetry-auto-yii` package.

#### Acceptance Criteria

1. THE Package SHALL use the PHP namespace `danvick\yii2\otel` for all classes.
2. THE Package SHALL declare `yiisoft/yii2` as a Composer `require` dependency.
3. THE Package SHALL declare `open-telemetry/sdk` as a Composer `require` dependency.
4. THE Package SHALL declare `ext-opentelemetry` as a Composer `suggest` dependency, not `require`.
5. THE Package SHALL declare `yiisoft/yii2-queue` as a Composer `suggest` dependency for queue instrumentation.
6. THE Package SHALL declare `yiisoft/yii2-redis` as a Composer `suggest` dependency for cache instrumentation.
7. THE Package SHALL support installation via Composer `path` repository for local development alongside the consuming application.
8. THE Package SHALL not depend on `open-telemetry/opentelemetry-auto-yii`.

### Requirement 2: Root Span Lifecycle Management

**User Story:** As a developer, I want the package to own the root span for every HTTP request and console command, so that all child spans created during processing are correctly nested under a single parent span.

#### Acceptance Criteria

1. WHEN a web application receives a request, THE OtelBootstrap SHALL create a Root_Span in the `EVENT_BEFORE_REQUEST` handler with the initial span name set to `HTTP {method}` (e.g., `HTTP GET`, `HTTP POST`).
2. WHEN a web request completes, THE OtelBootstrap SHALL end the Root_Span in the `EVENT_AFTER_REQUEST` handler.
3. WHEN a console command begins execution, THE OtelBootstrap SHALL create a Root_Span in the `EVENT_BEFORE_ACTION` handler with the initial span name set to `console/{route}`.
4. WHEN a console command completes execution, THE OtelBootstrap SHALL end the Root_Span in the `EVENT_AFTER_ACTION` handler.
5. THE OtelBootstrap SHALL activate the Root_Span's scope so that all child spans created during request processing are automatically parented under the Root_Span.
6. THE OtelBootstrap SHALL set standard HTTP semantic convention attributes on web Root_Spans: `http.method`, `http.url`, `http.target`, `http.scheme`, `http.host`, `http.status_code` (set on response).
7. IF an unhandled exception occurs during request processing, THEN THE OtelBootstrap SHALL record the exception on the Root_Span, set the span status to ERROR, and end the span.
8. THE OtelBootstrap SHALL detach the Root_Span scope when the span is ended to prevent context leaks.

### Requirement 3: Per-Application Service Name

**User Story:** As a DevOps engineer, I want each Yii2 application (admin, api, backend, frontend, console) to report a distinct service name, so that traces are correctly attributed in the observability backend.

#### Acceptance Criteria

1. THE OtelBootstrap SHALL accept a `serviceName` configuration property that identifies the application service.
2. WHEN `serviceName` is not configured, THE OtelBootstrap SHALL fall back to the `OTEL_SERVICE_NAME` environment variable.
3. THE OtelBootstrap SHALL pass the resolved service name to the Tracer obtained from the Globals_API as the instrumentation scope name.

### Requirement 4: SDK Setup via Environment Variables

**User Story:** As a DevOps engineer, I want the package to use standard OTEL environment variables for SDK configuration, so that I can control exporters, samplers, and endpoints without code changes.

#### Acceptance Criteria

1. THE Package SHALL obtain the TracerProvider via `Globals::tracerProvider()` and not create or configure its own TracerProvider.
2. THE Package SHALL obtain the LoggerProvider via `Globals::loggerProvider()` and not create or configure its own LoggerProvider.
3. THE Package SHALL rely on the OTEL_SDK auto-configuration for exporter setup via `OTEL_EXPORTER_OTLP_ENDPOINT`, `OTEL_EXPORTER_OTLP_PROTOCOL`, and `OTEL_TRACES_EXPORTER` environment variables.
4. THE Package SHALL rely on the OTEL_SDK auto-configuration for sampler setup via `OTEL_TRACES_SAMPLER` and `OTEL_TRACES_SAMPLER_ARG` environment variables.

### Requirement 5: Route-Aware Span Naming

**User Story:** As a developer, I want request spans renamed to the resolved Yii2 route after action dispatch, so that I can distinguish between identically-named controllers across different modules in the observability backend.

#### Acceptance Criteria

1. WHEN a web request completes action resolution, THE SpanRenamer SHALL rename the Root_Span to the resolved Yii2 route in the format `{moduleId}/{controllerId}/{actionId}` when a module is present.
2. WHEN the resolved route has no module prefix (controller is directly under the application), THE SpanRenamer SHALL use the format `{controllerId}/{actionId}`.
3. WHEN the request is a console command, THE SpanRenamer SHALL name the Root_Span using the format `console/{route}`.
4. IF the route cannot be resolved (e.g., 404 error before action dispatch), THEN THE SpanRenamer SHALL retain the initial span name set during Root_Span creation.
5. THE SpanRenamer SHALL be registered via `Application::EVENT_AFTER_ACTION` to rename the span after the action has been resolved and executed.

### Requirement 6: Database Query Child Spans

**User Story:** As a developer, I want each DB query to appear as a child span with sanitized SQL and connection metadata, so that I can identify slow queries and understand which database they target.

#### Acceptance Criteria

1. WHEN a database query is executed via any Yii2 `Connection` component, THE Package SHALL create a Child_Span under the current active span.
2. THE Package SHALL name each DB Child_Span using the pattern `DB {operation}` where `{operation}` is the uppercase SQL verb (e.g., `DB SELECT`, `DB INSERT`).
3. THE Package SHALL set the `db.system` Span_Attribute to `mysql` on each DB Child_Span.
4. THE Package SHALL set the `db.name` Span_Attribute to the database name extracted from the connection DSN.
5. THE Package SHALL set a `db.connection_name` Span_Attribute to the Yii2 Connection_Name component ID.
6. THE Package SHALL set the `db.statement` Span_Attribute to the SQL query text with bound parameter values replaced by `?` placeholders.
7. WHEN a query execution fails, THE Package SHALL record the exception on the Child_Span and set the span status to ERROR.
8. THE OtelBootstrap SHALL accept a `instrumentDb` boolean configuration property (default: `true`) to enable or disable DB instrumentation.
9. THE OtelBootstrap SHALL accept a `dbConnections` array configuration property listing the Yii2 connection component IDs to instrument (default: `['db']`).
10. THE Package SHALL instrument DB connections by hooking into `Connection::EVENT_AFTER_OPEN` to swap the `commandClass` to InstrumentedCommand.

### Requirement 7: Redis and Cache Operation Child Spans

**User Story:** As a developer, I want Redis and cache operations to appear as child spans with hit/miss indicators, so that I can analyze cache effectiveness and Redis latency.

#### Acceptance Criteria

1. WHEN a cache `get` operation is executed, THE InstrumentedCache SHALL create a Child_Span named `CACHE GET`.
2. THE InstrumentedCache SHALL set a `cache.hit` Span_Attribute to `true` when the cache returns a value, and `false` when the cache returns a miss.
3. WHEN a cache `set`, `delete`, or `flush` operation is executed, THE InstrumentedCache SHALL create a Child_Span named `CACHE {operation}` (e.g., `CACHE SET`, `CACHE DELETE`, `CACHE FLUSH`).
4. THE InstrumentedCache SHALL set the `cache.key` Span_Attribute to the cache key on each cache Child_Span (except `flush` which has no key).
5. THE InstrumentedCache SHALL set the `db.system` Span_Attribute to `redis` on each cache Child_Span.
6. THE OtelBootstrap SHALL accept an `instrumentCache` boolean configuration property (default: `true`) to enable or disable cache instrumentation.
7. THE OtelBootstrap SHALL swap the application's `cache` component class from `yii\redis\Cache` to InstrumentedCache when cache instrumentation is enabled.

### Requirement 8: Extensibility via SpanAttributeProviderInterface

**User Story:** As a developer, I want to inject application-specific span attributes (e.g., tenant context) into root spans via a provider interface, so that the package remains framework-level while supporting custom metadata.

#### Acceptance Criteria

1. THE Package SHALL expose a `SpanAttributeProviderInterface` with a single method `getAttributes(): array` that returns an associative array of attribute key-value pairs.
2. THE OtelBootstrap SHALL accept a `spanAttributeProviders` array configuration property listing class names or component IDs that implement SpanAttributeProviderInterface.
3. WHEN a Root_Span is created, THE OtelBootstrap SHALL invoke each registered SpanAttributeProviderInterface and set the returned attributes on the Root_Span.
4. WHEN a SpanAttributeProviderInterface returns an empty array, THE OtelBootstrap SHALL set no additional attributes from that provider.
5. IF a SpanAttributeProviderInterface throws an exception, THEN THE OtelBootstrap SHALL log the error and continue processing without setting attributes from that provider.

### Requirement 9: Queue Job Span Links

**User Story:** As a developer, I want queue job execution traces linked back to the originating request trace, so that I can follow the full lifecycle of an operation from HTTP request through background processing.

#### Acceptance Criteria

1. WHEN a job is pushed to the queue within an active trace, THE Package SHALL capture the current Trace_Context (`trace_id` and `span_id`) and store it as a `_otelTraceContext` property on the job object.
2. WHEN a queue job begins execution, THE Package SHALL create a new Root_Span for the job named `JOB {shortClassName}` (e.g., `JOB ExamClassRankingJob`).
3. WHEN the job payload contains a valid `_otelTraceContext`, THE Package SHALL add a Span_Link on the job Root_Span referencing the originating Trace_Context.
4. IF the `_otelTraceContext` is not present on the job payload, THEN THE Package SHALL create the job Root_Span without a Span_Link.
5. WHEN a queue job completes successfully, THE Package SHALL end the job Root_Span.
6. WHEN a queue job fails with an error, THE Package SHALL record the exception on the job Root_Span, set the span status to ERROR, and end the span.
7. THE OtelBootstrap SHALL accept an `instrumentQueue` boolean configuration property (default: `true`) to enable or disable queue instrumentation.

### Requirement 10: Yii2 Log Bridge to OTEL Log Exporter

**User Story:** As a developer, I want Yii2 application logs forwarded to the observability backend with trace correlation, so that I can view logs alongside traces for the same request.

#### Acceptance Criteria

1. THE Log_Bridge SHALL implement `yii\log\Target` so it can be configured as a standard Yii2 log target.
2. WHEN a log message is emitted within an active trace, THE Log_Bridge SHALL attach the current `trace_id` and `span_id` to the log record.
3. THE Log_Bridge SHALL map Yii2 log levels to OpenTelemetry log severity levels: `Logger::LEVEL_ERROR` to `ERROR`, `Logger::LEVEL_WARNING` to `WARN`, `Logger::LEVEL_INFO` to `INFO`, `Logger::LEVEL_TRACE` to `DEBUG`, `Logger::LEVEL_PROFILE` to `DEBUG`.
4. THE Log_Bridge SHALL include the Yii2 log category as the `log.category` attribute on each log record.
5. THE OtelBootstrap SHALL accept a `logBridge` boolean configuration property (default: `false`) to enable or disable the log bridge.
6. WHEN `logBridge` is enabled and `OTEL_LOGS_EXPORTER` is set to `otlp`, THE OtelBootstrap SHALL register the Log_Bridge as a Yii2 log target.

### Requirement 11: Graceful Degradation

**User Story:** As a developer, I want the instrumentation to degrade gracefully when the OTEL PHP extension is not loaded or the collector is unreachable, so that the application continues to function without errors.

#### Acceptance Criteria

1. WHEN the `opentelemetry` PHP extension is not loaded, THE OtelBootstrap SHALL skip all span creation, log bridging, and event handler registration without throwing exceptions.
2. WHEN the `OTEL_SDK_DISABLED` environment variable is set to `true`, THE OtelBootstrap SHALL skip all instrumentation.
3. IF the OTLP collector endpoint is unreachable during export, THEN THE Package SHALL rely on the OTEL_SDK's built-in error handling to log the failure and continue request processing.
4. THE Package SHALL not implement custom sampling logic and SHALL rely entirely on the OTEL_SDK's sampler configuration.

### Requirement 12: Toggleable Instrumentation Modules

**User Story:** As a developer, I want to enable or disable individual instrumentation modules via configuration, so that I can control which parts of the application are instrumented.

#### Acceptance Criteria

1. THE OtelBootstrap SHALL accept the following boolean configuration properties to toggle instrumentation modules: `instrumentDb` (default: `true`), `instrumentCache` (default: `true`), `instrumentQueue` (default: `true`), `instrumentViews` (default: `false`), `instrumentAr` (default: `false`), `instrumentHttpClient` (default: `false`), `instrumentAuth` (default: `false`), `instrumentMail` (default: `false`), `logBridge` (default: `false`).
2. WHEN `instrumentDb` is `false`, THE OtelBootstrap SHALL not register DB instrumentation event handlers.
3. WHEN `instrumentCache` is `false`, THE OtelBootstrap SHALL not swap the cache component to InstrumentedCache.
4. WHEN `instrumentQueue` is `false`, THE OtelBootstrap SHALL not register queue instrumentation event handlers.
5. WHEN `logBridge` is `false`, THE OtelBootstrap SHALL not register the OtelLogTarget.
6. WHEN `instrumentViews` is `false`, THE OtelBootstrap SHALL not register view rendering instrumentation event handlers.
7. WHEN `instrumentAr` is `false`, THE OtelBootstrap SHALL not register ActiveRecord instrumentation event handlers.
8. WHEN `instrumentHttpClient` is `false`, THE OtelBootstrap SHALL not register HTTP client instrumentation event handlers.
9. WHEN `instrumentAuth` is `false`, THE OtelBootstrap SHALL not register authentication instrumentation event handlers.
10. WHEN `instrumentMail` is `false`, THE OtelBootstrap SHALL not register mail sending instrumentation event handlers.

### Requirement 13: SQL Parameter Sanitization

**User Story:** As a developer, I want SQL statements in span attributes to have parameter values replaced with placeholders, so that sensitive data is not leaked into the observability backend.

#### Acceptance Criteria

1. THE Package SHALL replace all bound parameter values in SQL statements with `?` placeholders before setting the `db.statement` Span_Attribute.
2. THE Package SHALL sort parameter keys by length descending before replacement to prevent partial key matches (e.g., `:param10` replaced before `:param1`).
3. WHEN a SQL statement has no bound parameters, THE Package SHALL set the `db.statement` attribute to the original SQL text unchanged.

### Requirement 14: DSN Database Name Extraction

**User Story:** As a developer, I want the `db.name` span attribute to reflect the actual database name from the connection DSN, so that I can distinguish queries targeting different databases.

#### Acceptance Criteria

1. THE Package SHALL extract the database name from MySQL DSN strings in the format `mysql:host={host};port={port};dbname={name}` and set it as the `db.name` Span_Attribute.
2. WHEN the DSN does not contain a `dbname` parameter, THE Package SHALL set the `db.name` attribute to an empty string.

### Requirement 15: SQL Verb Extraction for Span Naming

**User Story:** As a developer, I want DB span names to reflect the SQL operation type, so that I can quickly identify the type of query in the observability backend.

#### Acceptance Criteria

1. THE Package SHALL extract the first word of the SQL statement as the SQL verb.
2. THE Package SHALL convert the extracted SQL verb to uppercase.
3. THE Package SHALL name each DB Child_Span as `DB {VERB}` (e.g., `DB SELECT`, `DB INSERT`, `DB UPDATE`, `DB DELETE`, `DB SHOW`).

### Requirement 16: Short Class Name Extraction for Job Span Naming

**User Story:** As a developer, I want job span names to use the short class name without the namespace, so that span names are concise and readable.

#### Acceptance Criteria

1. THE Package SHALL extract the short class name (last segment after the final `\` separator) from the fully-qualified job class name.
2. WHEN the class name has no namespace separator, THE Package SHALL use the full class name as the short name.
3. THE Package SHALL name each job Root_Span as `JOB {shortClassName}`.

### Requirement 17: Active Span Guard for Child Span Creation

**User Story:** As a developer, I want child spans to only be created when a valid parent span exists, so that DB and cache operations outside of an instrumented request do not create orphaned top-level spans.

#### Acceptance Criteria

1. WHEN no valid active span exists in the current context (the default non-recording span with an invalid context), THE Package SHALL skip Child_Span creation for DB queries and return the query result directly.
2. WHEN no valid active span exists in the current context, THE Package SHALL skip Child_Span creation for cache operations and return the cache result directly.
3. THE Package SHALL determine active span validity by checking `Span::getCurrent()->getContext()->isValid()`.

### Requirement 18: View Rendering Child Spans

**User Story:** As a developer, I want view rendering to appear as child spans with the view file name, so that I can identify slow templates and understand how much time is spent in the view layer versus controller/model logic.

#### Acceptance Criteria

1. WHEN a view file is rendered via `View::EVENT_BEFORE_RENDER`, THE Package SHALL create a Child_Span named `VIEW {viewFile}` where `{viewFile}` is the short view file path (relative to the application's view directory, e.g., `tenants/index`, `layouts/main`).
2. WHEN the view rendering completes via `View::EVENT_AFTER_RENDER`, THE Package SHALL end the Child_Span.
3. THE Package SHALL set a `view.file` Span_Attribute containing the full view file path on each view Child_Span.
4. THE Package SHALL nest view Child_Spans correctly when views render other views (e.g., a layout rendering a content view, or `renderPartial()` calls within a view), producing a parent-child hierarchy of view spans.
5. IF an exception occurs during view rendering, THEN THE Package SHALL record the exception on the Child_Span, set the span status to ERROR, and end the span.
6. THE OtelBootstrap SHALL accept an `instrumentViews` boolean configuration property (default: `false`) to enable or disable view rendering instrumentation.
7. THE Package SHALL use the active span guard to skip view span creation when no valid parent span exists.

### Requirement 19: ActiveRecord Lifecycle Child Spans

**User Story:** As a developer, I want ActiveRecord find, save, and delete operations to appear as child spans with the model class name, so that I can understand how much time is spent in model hydration, validation, and persistence versus raw SQL execution.

#### Acceptance Criteria

1. WHEN an ActiveRecord `find()` query is executed (via `ActiveQuery::all()`, `ActiveQuery::one()`, or `ActiveQuery::each()`), THE Package SHALL create a Child_Span named `AR FIND {ModelShortName}` (e.g., `AR FIND Tenant`, `AR FIND Student`).
2. WHEN an ActiveRecord `save()` operation is executed (insert or update), THE Package SHALL create a Child_Span named `AR SAVE {ModelShortName}`.
3. WHEN an ActiveRecord `delete()` operation is executed, THE Package SHALL create a Child_Span named `AR DELETE {ModelShortName}`.
4. THE Package SHALL set an `ar.model` Span_Attribute containing the short class name of the ActiveRecord model on each AR Child_Span.
5. THE Package SHALL set an `ar.operation` Span_Attribute containing the operation type (`find`, `save`, `delete`) on each AR Child_Span.
6. WHEN an AR `save()` span is created, THE Package SHALL set an `ar.is_new` Span_Attribute to `true` for inserts and `false` for updates.
7. DB query Child_Spans created during an AR operation SHALL nest under the AR Child_Span, showing the relationship between the high-level model operation and the underlying SQL queries.
8. IF an exception occurs during an AR operation, THEN THE Package SHALL record the exception on the Child_Span, set the span status to ERROR, and end the span.
9. THE OtelBootstrap SHALL accept an `instrumentAr` boolean configuration property (default: `false`) to enable or disable ActiveRecord instrumentation.
10. THE Package SHALL use the active span guard to skip AR span creation when no valid parent span exists.

### Requirement 20: HTTP Client Outbound Request Child Spans

**User Story:** As a developer, I want outbound HTTP requests made via `yii\httpclient\Client` to appear as child spans with URL, method, and status code, so that I can identify slow third-party API calls and understand external service latency.

#### Acceptance Criteria

1. WHEN an HTTP request is sent via `yii\httpclient\Client::EVENT_BEFORE_SEND`, THE Package SHALL create a Child_Span named `HTTP {METHOD} {host}` (e.g., `HTTP POST api.jumbefupi.com`, `HTTP GET chatwoot.example.com`).
2. WHEN the HTTP response is received via `yii\httpclient\Client::EVENT_AFTER_SEND`, THE Package SHALL end the Child_Span and set the `http.status_code` Span_Attribute to the response status code.
3. THE Package SHALL set the following Span_Attributes on each HTTP client Child_Span: `http.method` (request method), `http.url` (full request URL), `http.host` (target hostname), `http.status_code` (response status code, set after response).
4. THE Package SHALL set the span kind to `KIND_CLIENT` on HTTP client Child_Spans.
5. IF the HTTP request fails with a transport error (connection refused, timeout, etc.), THEN THE Package SHALL record the exception on the Child_Span, set the span status to ERROR, and end the span.
6. THE OtelBootstrap SHALL accept an `instrumentHttpClient` boolean configuration property (default: `false`) to enable or disable HTTP client instrumentation.
7. THE Package SHALL use the active span guard to skip HTTP client span creation when no valid parent span exists.

### Requirement 21: Authentication Event Child Spans

**User Story:** As a developer, I want login and logout events to appear as child spans, so that I can see authentication activity in request traces and correlate auth timing with overall request latency.

#### Acceptance Criteria

1. WHEN a user login is attempted via `yii\web\User::EVENT_BEFORE_LOGIN`, THE Package SHALL create a Child_Span named `AUTH LOGIN`.
2. WHEN the login completes via `yii\web\User::EVENT_AFTER_LOGIN`, THE Package SHALL end the Child_Span and set an `auth.success` Span_Attribute to `true`.
3. WHEN a user logout is attempted via `yii\web\User::EVENT_BEFORE_LOGOUT`, THE Package SHALL create a Child_Span named `AUTH LOGOUT`.
4. WHEN the logout completes via `yii\web\User::EVENT_AFTER_LOGOUT`, THE Package SHALL end the Child_Span.
5. THE Package SHALL set an `auth.user_id` Span_Attribute on login/logout spans when the user identity is available.
6. THE OtelBootstrap SHALL accept an `instrumentAuth` boolean configuration property (default: `false`) to enable or disable authentication instrumentation.
7. THE Package SHALL use the active span guard to skip auth span creation when no valid parent span exists.

### Requirement 22: Mail Sending Child Spans

**User Story:** As a developer, I want mail sending operations to appear as child spans, so that I can see email delivery time in request traces and identify slow SMTP connections.

#### Acceptance Criteria

1. WHEN a mail message is sent via `yii\mail\BaseMailer::EVENT_BEFORE_SEND`, THE Package SHALL create a Child_Span named `MAIL SEND`.
2. WHEN the mail sending completes via `yii\mail\BaseMailer::EVENT_AFTER_SEND`, THE Package SHALL end the Child_Span and set a `mail.success` Span_Attribute to `true` if the message was sent successfully, `false` otherwise.
3. THE Package SHALL set a `mail.to` Span_Attribute containing the recipient address(es) on each mail Child_Span.
4. THE Package SHALL set a `mail.subject` Span_Attribute containing the message subject on each mail Child_Span.
5. IF the mail sending fails with an exception, THEN THE Package SHALL record the exception on the Child_Span, set the span status to ERROR, and end the span.
6. THE OtelBootstrap SHALL accept an `instrumentMail` boolean configuration property (default: `false`) to enable or disable mail sending instrumentation.
7. THE Package SHALL use the active span guard to skip mail span creation when no valid parent span exists.
