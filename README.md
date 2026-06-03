# yii2-opentelemetry

OpenTelemetry instrumentation for Yii2 applications.

## Overview

This package provides complete OpenTelemetry instrumentation for Yii2 applications, including:

- **Root span lifecycle** — creates and ends the root span per request, ensuring all child spans are correctly parented
- **W3C trace context propagation** — extracts `traceparent`/`tracestate` from incoming requests and injects them into outbound HTTP requests for end-to-end distributed tracing
- **Route-aware span naming** — renames root spans to the resolved Yii2 route after action dispatch
- **Metrics** — `http.server.requests`, `http.server.errors`, and `http.server.duration` counters/histograms per request
- **DB query instrumentation** — child spans for every SQL query with sanitized statements and connection metadata
- **Cache instrumentation** — child spans for Redis cache operations with hit/miss indicators
- **Queue job instrumentation** — span links connecting job execution traces back to originating request traces
- **HTTP client instrumentation** — child spans for outbound HTTP requests with W3C context injection
- **View rendering instrumentation** — child spans for view/layout/partial rendering with nested hierarchy
- **ActiveRecord instrumentation** — child spans for save and delete operations with model metadata
- **Authentication instrumentation** — child spans for login/logout events
- **Mail instrumentation** — child spans for mail sending with recipient and success tracking
- **Log bridge** — forwards Yii2 log messages to the OTEL log exporter with automatic trace/span correlation, structured exception formatting, and configurable level filtering
- **Extensible span attributes** — `SpanAttributeProviderInterface` for injecting application-specific attributes (e.g., tenant context)

## Requirements

- PHP >= 8.0
- Yii2 >= 2.0.16
- `open-telemetry/sdk` ^1.0 or 2.x-dev
- `open-telemetry/exporter-otlp` (for OTLP export)
- `ext-opentelemetry` (suggested — enables auto-configuration and better performance)
- `ext-protobuf` (suggested — faster serialisation for OTLP)
- `yiisoft/yii2-redis` (suggested — required for cache instrumentation)
- `yiisoft/yii2-queue` (suggested — required for queue instrumentation)
- `yiisoft/yii2-httpclient` (suggested — required for HTTP client instrumentation)

## Installation

Add the GitHub repository and require the package in your `composer.json`:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/danvick/yii2-opentelemetry"
        }
    ],
    "require": {
        "danvick/yii2-opentelemetry": "dev-main"
    }
}
```

Then run:

```bash
composer update danvick/yii2-opentelemetry
```

For local development alongside your application, use a path repository instead:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../yii2-opentelemetry",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "danvick/yii2-opentelemetry": "@dev"
    }
}
```

## OpenTelemetry SDK Configuration

This package does not configure the OpenTelemetry SDK itself. It obtains the `TracerProvider`, `MeterProvider`, and `LoggerProvider` from the SDK's auto-configuration via `OpenTelemetry\API\Globals`. All SDK behaviour is controlled through standard OTEL environment variables.

### Required Environment Variables

```bash
# Service name — identifies this application in your observability backend.
# Each Yii2 app (admin, api, backend, frontend, console) should have its own name.
OTEL_SERVICE_NAME=my-app-backend

# OTLP collector endpoint
OTEL_EXPORTER_OTLP_ENDPOINT=https://your-collector.example.com

# Export protocol (http/protobuf is recommended for PHP)
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf

# Exporters
OTEL_TRACES_EXPORTER=otlp
OTEL_METRICS_EXPORTER=otlp
OTEL_LOGS_EXPORTER=otlp     # Set to 'none' to disable log export

# PHP extension auto-instrumentation
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_PROPAGATORS=baggage,tracecontext

# SDK kill switch (set to 'true' to disable all instrumentation)
OTEL_SDK_DISABLED=false
```

### Sampling Configuration

```bash
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1.0   # 1.0 = 100%, 0.1 = 10%
```

| Environment | Sampler | Rate | Notes |
|---|---|---|---|
| Development | `parentbased_always_on` | — | Capture everything |
| Staging | `parentbased_traceidratio` | `1.0` | Capture everything |
| Production | `parentbased_traceidratio` | `0.1` – `0.25` | 10–25% to control overhead |

### Important: Early Environment Loading

The OTEL PHP extension reads environment variables at autoload time — before your Yii2 app bootstraps. If you use `vlucas/phpdotenv`, the `OTEL_*` vars must be loaded before `vendor/autoload.php`:

```php
<?php
// Load OTEL_* env vars BEFORE vendor/autoload.php
$dotenvFile = dirname(__DIR__, 2) . '/.env';
if (file_exists($dotenvFile)) {
    foreach (file($dotenvFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line !== '' && $line[0] !== '#' && str_starts_with($line, 'OTEL_')) {
            putenv($line);
        }
    }
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';
```

## Bootstrap Configuration

Add `OtelBootstrap` to your application's bootstrap array. In a Yii2 Advanced Template the recommended approach is to configure it per-environment in `common/config/main-local.php` so dev and prod can have different instrumentation levels.

### Development — instrument everything

```php
// environments/dev/common/config/main-local.php
'bootstrap' => [
    [
        'class' => \danvick\yii2\otel\OtelBootstrap::class,
        'instrumentDb'         => true,
        'instrumentCache'      => true,
        'instrumentQueue'      => true,
        'instrumentViews'      => true,   // per-view spans (can be noisy)
        'instrumentAr'         => true,   // AR save/delete spans
        'instrumentHttpClient' => true,
        'instrumentAuth'       => true,
        'instrumentMail'       => true,
        'logBridge'            => true,   // INFO + WARNING + ERROR in dev
        'dbConnections'        => ['db'],
        'spanAttributeProviders' => [
            \app\components\UserContextSpanAttributes::class,
        ],
    ],
],
```

### Production — sensible defaults

```php
// environments/prod/common/config/main-local.php
'bootstrap' => [
    [
        'class' => \danvick\yii2\otel\OtelBootstrap::class,
        'instrumentDb'         => true,
        'instrumentCache'      => true,
        'instrumentQueue'      => true,
        'instrumentViews'      => false,  // too noisy in prod
        'instrumentAr'         => false,  // too noisy in prod
        'instrumentHttpClient' => true,
        'instrumentAuth'       => false,
        'instrumentMail'       => true,
        'logBridge'            => true,   // WARNING + ERROR only in prod
        'dbConnections'        => ['db'],
        'spanAttributeProviders' => [
            \app\components\UserContextSpanAttributes::class,
        ],
    ],
],
```

### Bootstrap Properties

| Property | Type | Default | Description |
|---|---|---|---|
| `serviceName` | `string\|null` | `null` | Tracer scope name. Falls back to `OTEL_SERVICE_NAME` env var, then `yii2-app`. |
| `instrumentDb` | `bool` | `true` | DB query child spans (`DB SELECT`, `DB INSERT`, etc.) |
| `instrumentCache` | `bool` | `true` | Redis cache child spans (`CACHE GET`, `CACHE SET`, etc.) |
| `instrumentQueue` | `bool` | `true` | Queue job spans with trace linking (`JOB ClassName`) |
| `instrumentViews` | `bool` | `false` | View rendering child spans — can generate many spans per request |
| `instrumentAr` | `bool` | `false` | ActiveRecord save/delete child spans |
| `instrumentHttpClient` | `bool` | `false` | Outbound HTTP request child spans with W3C context injection |
| `instrumentAuth` | `bool` | `false` | Login/logout child spans |
| `instrumentMail` | `bool` | `false` | Mail sending child spans |
| `logBridge` | `bool` | `false` | Forward Yii2 logs to OTEL (requires `OTEL_LOGS_EXPORTER=otlp`) |
| `dbConnections` | `array` | `['db']` | Yii2 DB connection component IDs to instrument |
| `spanAttributeProviders` | `array` | `[]` | Classes implementing `SpanAttributeProviderInterface` |

## Metrics

When `OTEL_METRICS_EXPORTER=otlp`, the following instruments are recorded automatically on every HTTP request:

| Instrument | Type | Unit | Description |
|---|---|---|---|
| `http.server.requests` | Counter | `{request}` | Total requests handled |
| `http.server.errors` | Counter | `{error}` | Requests resulting in 5xx responses |
| `http.server.duration` | Histogram | `ms` | Request duration in milliseconds |

All three are tagged with `http.method`, `http.route`, and `http.status_code`.

## Span Reference

### Always Active

| Span | Description | Key Attributes |
|---|---|---|
| `HTTP GET` → `module/controller/action` | Root span for web requests | `http.method`, `http.url`, `http.status_code` |
| `console/migrate/up` | Root span for console commands | — |

### instrumentDb

| Span | Attributes |
|---|---|
| `DB SELECT` / `DB INSERT` / `DB UPDATE` / `DB DELETE` | `db.system`, `db.name`, `db.connection_name`, `db.statement` (sanitized) |

### instrumentCache

| Span | Attributes |
|---|---|
| `CACHE GET` | `db.system=redis`, `cache.key`, `cache.hit` |
| `CACHE SET` / `CACHE DELETE` / `CACHE FLUSH` | `db.system=redis`, `cache.key` |

### instrumentQueue

| Span | Attributes |
|---|---|
| `JOB SendEmailJob` | SpanLink to originating request trace |

### instrumentViews

| Span | Attributes |
|---|---|
| `VIEW site/index` / `VIEW layouts/main` | `view.file` |

Nested views produce a parent-child hierarchy: layout → content → partials.

### instrumentAr

| Span | Attributes |
|---|---|
| `AR SAVE Post` | `ar.model`, `ar.operation=save`, `ar.is_new` |
| `AR DELETE Comment` | `ar.model`, `ar.operation=delete` |

### instrumentHttpClient

| Span | Attributes |
|---|---|
| `HTTP POST api.example.com` | `http.method`, `http.url`, `http.host`, `http.status_code` |

W3C `traceparent` and `tracestate` headers are injected into the outbound request automatically.

### instrumentAuth

| Span | Attributes |
|---|---|
| `AUTH LOGIN` | `auth.user_id`, `auth.success` |
| `AUTH LOGOUT` | `auth.user_id` |

### instrumentMail

| Span | Attributes |
|---|---|
| `MAIL SEND` | `mail.to`, `mail.subject`, `mail.success` |

## Log Bridge

When `logBridge=true` and `OTEL_LOGS_EXPORTER=otlp`, Yii2 log messages are forwarded to your OTLP backend with:

- **Automatic trace/span correlation** — log records carry the active W3C trace context so SigNoz and other backends can link logs to traces without manual configuration
- **Level filtering** — defaults to `WARNING` + `ERROR` in production; automatically upgrades to `INFO` + above when `YII_ENV=dev`
- **Category exclusions** — noisy internal Yii categories (`yii\db\Command`, `yii\web\HttpException:404`, `yii\debug\*`) are excluded by default since they are already captured as spans
- **Structured exception formatting** — `Throwable` bodies are serialised with class, message, and full stack trace, plus structured `exception.type`, `exception.message`, and `exception.stacktrace` attributes
- **End-of-request flushing** — uses Yii's default flush behaviour (at request shutdown) to avoid blocking mid-request network calls to the collector

## Custom Span Attributes

Inject application-specific attributes into root spans by implementing `SpanAttributeProviderInterface`:

```php
use danvick\yii2\otel\SpanAttributeProviderInterface;

class UserContextSpanAttributes implements SpanAttributeProviderInterface
{
    public function getAttributes(): array
    {
        $user = Yii::$app->user ?? null;
        if ($user === null || $user->isGuest) {
            return [];
        }

        return [
            'user.id'    => $user->id,
            'user.email' => $user->identity->email ?? null,
        ];
    }
}
```

Providers that throw exceptions are caught and logged — they will never break your application.

## Distributed Tracing

Incoming `traceparent`/`tracestate` headers are automatically extracted and used as the parent context for the root span. This means if an upstream service (load balancer, API gateway, frontend) sends a W3C trace context header, this application's spans will appear as children in the same trace.

Outbound HTTP requests made via `yiisoft/yii2-httpclient` automatically have `traceparent`/`tracestate` injected so downstream services can continue the trace.

## Graceful Degradation

- If `ext-opentelemetry` is not installed, all instrumentation is silently skipped
- If `OTEL_SDK_DISABLED=true`, all instrumentation is silently skipped
- If the OTLP collector is unreachable, the SDK handles errors internally — your application is not affected
- No exceptions propagate from the instrumentation layer

## License

MIT License. See [LICENSE](LICENSE) for details.
