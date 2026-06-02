# Product Overview

yii2-opentelemetry is a Composer package (`danvick/yii2-opentelemetry`) that provides OpenTelemetry instrumentation for Yii2 PHP applications.

It hooks into Yii2's event system to automatically create OTEL spans for HTTP requests, console commands, DB queries, cache operations, queue jobs, view rendering, ActiveRecord operations, outbound HTTP calls, authentication events, and mail sending. It also bridges Yii2 logs to the OTEL log exporter.

Key design goals:
- Zero-config for the OTEL SDK itself — relies on `OpenTelemetry\API\Globals` and standard `OTEL_*` environment variables
- Opt-in instrumentation — each subsystem (DB, cache, queue, views, AR, HTTP client, auth, mail, logs) is toggled via boolean flags on `OtelBootstrap`
- Graceful degradation — if `ext-opentelemetry` is missing or `OTEL_SDK_DISABLED=true`, all instrumentation is silently skipped
- Extensibility — `SpanAttributeProviderInterface` lets consuming apps inject custom attributes (e.g., tenant context) into root spans
