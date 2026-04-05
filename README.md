# yii2-opentelemetry

OpenTelemetry instrumentation for Yii2 applications.

## Overview

This package provides complete OpenTelemetry instrumentation for Yii2 applications, including:

- Root span lifecycle management — creates and ends the root span per request, ensuring all child spans are correctly parented
- Route-aware span naming — renames root spans to the resolved Yii2 route after action dispatch
- DB query instrumentation — child spans for every SQL query with sanitized statements and connection metadata
- Cache instrumentation — child spans for Redis cache operations with hit/miss indicators
- Queue job instrumentation — span links connecting job execution traces back to originating request traces
- View rendering instrumentation — child spans for view/layout/partial rendering with nested hierarchy
- ActiveRecord instrumentation — child spans for save and delete operations with model metadata
- HTTP client instrumentation — child spans for outbound HTTP requests with URL, method, and status
- Authentication instrumentation — child spans for login/logout events
- Mail instrumentation — child spans for mail sending with recipient and success tracking
- Log bridge — forwards Yii2 log messages to the OTEL log exporter with trace correlation
- Extensible span attributes — `SpanAttributeProviderInterface` for injecting application-specific attributes (e.g., tenant context)

## Requirements

- PHP >= 8.0
- Yii2 >= 2.0.16
- `open-telemetry/sdk` ^1.0 or 2.x-dev
- `open-telemetry/exporter-otlp` (for OTLP export)
- `ext-opentelemetry` (suggested — enables auto-configuration and better performance)
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
            "url": "../yii2-opentelemetry"
        }
    ],
    "require": {
        "danvick/yii2-opentelemetry": "@dev"
    }
}
```

## OpenTelemetry SDK Configuration

This package does not configure the OpenTelemetry SDK itself. It obtains the `TracerProvider` and `LoggerProvider` from the SDK's auto-configuration via `OpenTelemetry\API\Globals`. All SDK behavior is controlled through standard OTEL environment variables.

### Required Environment Variables

Set these in your `.env` file, `docker-compose.yml`, or container environment:

```bash
# Service name — identifies this application in your observability backend.
# Each Yii2 app (admin, api, backend, frontend, console) should have its own name.
OTEL_SERVICE_NAME=my-app-backend

# OTLP collector endpoint
OTEL_EXPORTER_OTLP_ENDPOINT=http://signoz-collector:4318

# Export protocol (http/protobuf is recommended for PHP)
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf

# Traces exporter
OTEL_TRACES_EXPORTER=otlp
```

### Important: Early Environment Loading

The OTEL PHP extension reads environment variables at autoload time (before your Yii2 app bootstraps). If you use `vlucas/phpdotenv` to load `.env` files, the OTEL vars must be loaded before `vendor/autoload.php`. Add this to your shared autoload file (e.g., `common/config/autoload.php`):

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

### Sampling Configuration

Control how many traces are recorded and exported:

```bash
# Sampler type — parentbased_traceidratio is recommended for production
OTEL_TRACES_SAMPLER=parentbased_traceidratio

# Sampling rate: 1.0 = 100%, 0.1 = 10%, 0.25 = 25%
OTEL_TRACES_SAMPLER_ARG=1.0
```

Recommended values by environment:

| Environment | Sampler | Rate | Notes |
|---|---|---|---|
| Development | `parentbased_always_on` | — | Capture everything |
| Staging | `parentbased_traceidratio` | `1.0` | Capture everything |
| Production | `parentbased_traceidratio` | `0.1` – `0.25` | 10-25% sampling to control overhead |

### Log Bridge Configuration

To forward Yii2 logs to your observability backend via OTLP:

```bash
# Set to 'otlp' to enable log export, 'none' to disable
OTEL_LOGS_EXPORTER=otlp
```

The log bridge also requires `logBridge => true` in the bootstrap config (see below).

### Kill Switch

Disable all instrumentation without code changes:

```bash
OTEL_SDK_DISABLED=false
```

### PHP Extension Configuration

If you have the `ext-opentelemetry` PHP extension installed:

```bash
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_PROPAGATORS=baggage,tracecontext
```

### Example .env File

```bash
# === OpenTelemetry Configuration ===
OTEL_SERVICE_NAME=my-app-backend
OTEL_EXPORTER_OTLP_ENDPOINT=http://signoz-collector:4318
OTEL_EXPORTER_OTLP_PROTOCOL=http/protobuf
OTEL_TRACES_EXPORTER=otlp
OTEL_LOGS_EXPORTER=none
OTEL_TRACES_SAMPLER=parentbased_traceidratio
OTEL_TRACES_SAMPLER_ARG=1.0
OTEL_SDK_DISABLED=false
OTEL_PHP_AUTOLOAD_ENABLED=true
OTEL_PROPAGATORS=baggage,tracecontext
```

### Docker Compose Example

For multi-app setups (like Yii2 Advanced Template), set `OTEL_SERVICE_NAME` per container:

```yaml
services:
  backend:
    environment:
      OTEL_SERVICE_NAME: myapp-backend
      OTEL_EXPORTER_OTLP_ENDPOINT: http://signoz-collector:4318
      OTEL_EXPORTER_OTLP_PROTOCOL: http/protobuf
      OTEL_TRACES_EXPORTER: otlp
      OTEL_TRACES_SAMPLER: parentbased_traceidratio
      OTEL_TRACES_SAMPLER_ARG: "1.0"

  api:
    environment:
      OTEL_SERVICE_NAME: myapp-api
      OTEL_EXPORTER_OTLP_ENDPOINT: http://signoz-collector:4318
      # ... same config, different service name

  admin:
    environment:
      OTEL_SERVICE_NAME: myapp-admin

  console:
    environment:
      OTEL_SERVICE_NAME: myapp-console
```

## Bootstrap Configuration

Add `OtelBootstrap` to your application bootstrap array. In a Yii2 Advanced Template, this goes in `common/config/main.php`:

```php
'bootstrap' => [
    [
        'class' => \danvick\yii2\otel\OtelBootstrap::class,
        // Service name — omit to use OTEL_SERVICE_NAME env var (recommended)
        // 'serviceName' => 'my-app',
        'instrumentDb' => true,
        'instrumentCache' => true,
        'instrumentQueue' => true,
        'instrumentViews' => false,
        'instrumentAr' => false,
        'instrumentHttpClient' => false,
        'instrumentAuth' => false,
        'instrumentMail' => false,
        'logBridge' => false,
        'dbConnections' => ['db'],
        'spanAttributeProviders' => [],
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
| `instrumentViews` | `bool` | `false` | View rendering child spans (`VIEW tenants/index`, `VIEW layouts/main`) |
| `instrumentAr` | `bool` | `false` | ActiveRecord save/delete child spans (`AR SAVE Tenant`, `AR DELETE Student`) |
| `instrumentHttpClient` | `bool` | `false` | Outbound HTTP request child spans (`HTTP POST api.example.com`) |
| `instrumentAuth` | `bool` | `false` | Login/logout child spans (`AUTH LOGIN`, `AUTH LOGOUT`) |
| `instrumentMail` | `bool` | `false` | Mail sending child spans (`MAIL SEND`) |
| `logBridge` | `bool` | `false` | Forward Yii2 logs to OTEL (also requires `OTEL_LOGS_EXPORTER=otlp`) |
| `dbConnections` | `array` | `['db']` | Yii2 DB connection component IDs to instrument |
| `spanAttributeProviders` | `array` | `[]` | Classes implementing `SpanAttributeProviderInterface` |

## Span Types

### Always Active

| Span Name | Description | Attributes |
|---|---|---|
| `HTTP GET` → `module/controller/action` | Root span for web requests, renamed to resolved route | `http.method`, `http.url`, `http.target`, `http.scheme`, `http.host`, `http.status_code` |
| `console/migrate/up` | Root span for console commands | — |

### instrumentDb (default: on)

| Span Name | Description | Attributes |
|---|---|---|
| `DB SELECT` | SQL query | `db.system`, `db.name`, `db.connection_name`, `db.statement` |
| `DB INSERT` | SQL insert | Same as above |
| `DB UPDATE` | SQL update | Same as above |
| `DB DELETE` | SQL delete | Same as above |

### instrumentCache (default: on)

| Span Name | Description | Attributes |
|---|---|---|
| `CACHE GET` | Cache read | `db.system=redis`, `cache.key`, `cache.hit` |
| `CACHE SET` | Cache write | `db.system=redis`, `cache.key` |
| `CACHE DELETE` | Cache delete | `db.system=redis`, `cache.key` |
| `CACHE FLUSH` | Cache flush | `db.system=redis` |

### instrumentQueue (default: on)

| Span Name | Description | Attributes |
|---|---|---|
| `JOB SendEmailJob` | Queue job execution with SpanLink to originating request | — |

### instrumentViews (default: off)

| Span Name | Description | Attributes |
|---|---|---|
| `VIEW tenants/index` | View file rendering | `view.file` (full path) |
| `VIEW layouts/main` | Layout rendering (parent of content view spans) | `view.file` |

Nested views produce a parent-child hierarchy: layout → content → partials.

### instrumentAr (default: off)

| Span Name | Description | Attributes |
|---|---|---|
| `AR SAVE Tenant` | ActiveRecord insert or update | `ar.model`, `ar.operation=save`, `ar.is_new` |
| `AR DELETE Student` | ActiveRecord delete | `ar.model`, `ar.operation=delete` |

DB query spans nest under AR spans, showing the relationship between model operations and underlying SQL.

### instrumentHttpClient (default: off)

| Span Name | Description | Attributes |
|---|---|---|
| `HTTP POST api.jumbefupi.com` | Outbound HTTP request | `http.method`, `http.url`, `http.host`, `http.status_code` |

### instrumentAuth (default: off)

| Span Name | Description | Attributes |
|---|---|---|
| `AUTH LOGIN` | User login | `auth.user_id`, `auth.success` |
| `AUTH LOGOUT` | User logout | `auth.user_id` |

### instrumentMail (default: off)

| Span Name | Description | Attributes |
|---|---|---|
| `MAIL SEND` | Mail sending | `mail.to`, `mail.subject`, `mail.success` |

## Custom Span Attributes

Inject application-specific attributes into root spans by implementing `SpanAttributeProviderInterface`:

```php
use danvick\yii2\otel\SpanAttributeProviderInterface;

class TenantSpanAttributes implements SpanAttributeProviderInterface
{
    public function getAttributes(): array
    {
        $tenant = $this->resolveCurrentTenant();
        if ($tenant === null) {
            return [];
        }

        return [
            'tenant.id' => $tenant->id,
            'tenant.name' => $tenant->name,
        ];
    }
}
```

Register in the bootstrap config:

```php
'spanAttributeProviders' => [
    \app\components\TenantSpanAttributes::class,
],
```

Providers that throw exceptions are caught and logged — they won't break your application.

## Graceful Degradation

- If `ext-opentelemetry` is not installed, all instrumentation is silently skipped
- If `OTEL_SDK_DISABLED=true`, all instrumentation is silently skipped
- If the OTLP collector is unreachable, the SDK handles the error internally
- No exceptions are thrown by the instrumentation layer

## License

MIT License. See [LICENSE](LICENSE) for details.
