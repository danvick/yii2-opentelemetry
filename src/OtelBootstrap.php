<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Behavior\Internal\Logging;
use OpenTelemetry\API\Globals;
use OpenTelemetry\API\Logs\LoggerInterface;
use OpenTelemetry\API\Metrics\CounterInterface;
use OpenTelemetry\API\Metrics\HistogramInterface;
use OpenTelemetry\API\Trace\Span;
use OpenTelemetry\API\Trace\SpanInterface;
use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use OpenTelemetry\Context\Context;
use OpenTelemetry\Context\ScopeInterface;
use Yii;
use yii\base\ActionEvent;
use yii\log\Logger;
use yii\base\Application;
use yii\base\BootstrapInterface;
use yii\base\Component;
use yii\base\Event;
use yii\console\Application as ConsoleApplication;
use yii\web\Application as WebApplication;
use yii\web\Response;

/**
 * Central entry point for OpenTelemetry instrumentation in Yii2 applications.
 *
 * Manages the root span lifecycle by creating the root span in EVENT_BEFORE_REQUEST
 * (web) or EVENT_BEFORE_ACTION (console) and ending it in EVENT_AFTER_REQUEST /
 * EVENT_AFTER_ACTION. This ensures all child spans (DB, cache, queue) are correctly
 * parented under the root span.
 *
 * Extends yii\base\Component so configuration properties work with Yii2's
 * object configuration system.
 *
 * Requirements: 2.1-2.8, 3.1-3.3, 4.1-4.4, 8.2-8.5, 11.1-11.4, 12.1-12.5
 */
class OtelBootstrap extends Component implements BootstrapInterface
{
    /**
     * @var string|null Service name for the tracer scope.
     * Falls back to OTEL_SERVICE_NAME env var when null.
     */
    public ?string $serviceName = null;

    /** @var bool Enable/disable DB query instrumentation */
    public bool $instrumentDb = true;

    /** @var bool Enable/disable cache operation instrumentation */
    public bool $instrumentCache = true;

    /** @var bool Enable/disable queue job instrumentation */
    public bool $instrumentQueue = true;

    /** @var bool Enable/disable Yii2 log bridge to OTEL */
    public bool $logBridge = false;

    /** @var bool Enable/disable view rendering instrumentation */
    public bool $instrumentViews = false;

    /** @var bool Enable/disable ActiveRecord lifecycle instrumentation */
    public bool $instrumentAr = false;

    /** @var bool Enable/disable HTTP client outbound request instrumentation */
    public bool $instrumentHttpClient = false;

    /** @var bool Enable/disable authentication event instrumentation */
    public bool $instrumentAuth = false;

    /** @var bool Enable/disable mail sending instrumentation */
    public bool $instrumentMail = false;

    /** @var array Yii2 connection component IDs to instrument */
    public array $dbConnections = ['db'];

    /**
     * @var array Class names or component IDs implementing SpanAttributeProviderInterface.
     * Each provider's getAttributes() is called when a root span is created.
     */
    public array $spanAttributeProviders = [];

    private ?TracerInterface $tracer = null;
    private ?LoggerInterface $logger = null;

    /** @var SpanInterface|null The active root span */
    private ?SpanInterface $rootSpan = null;

    /** @var ScopeInterface|null The scope for the active root span */
    private ?ScopeInterface $rootScope = null;

    // Metrics instruments
    private ?CounterInterface $requestCounter = null;
    private ?CounterInterface $errorCounter = null;
    private ?HistogramInterface $requestDuration = null;

    /** @var float|null Request start time in seconds with microsecond precision */
    private ?float $requestStartTime = null;

    /**
     * Checks whether the OTEL PHP extension is loaded.
     * Overridable in tests.
     */
    protected function isExtensionLoaded(): bool
    {
        return extension_loaded('opentelemetry');
    }

    /**
     * Reads an environment variable.
     * Overridable in tests.
     */
    protected function getEnv(string $name): string|false
    {
        return getenv($name);
    }

    /**
     * @inheritdoc
     */
    public function bootstrap($app): void
    {
        // Gate: skip all instrumentation if the OTEL PHP extension is not loaded
        if (!$this->isExtensionLoaded()) {
            return;
        }

        // Gate: skip if the SDK is explicitly disabled
        if ($this->getEnv('OTEL_SDK_DISABLED') === 'true') {
            return;
        }

        // Configure OTEL SDK internal logger to forward errors to Yii::warning()
        $this->configureOtelErrorHandler();

        // Obtain providers from OTEL SDK globals (Requirement 4.1, 4.2)
        $tracerProvider = Globals::tracerProvider();
        $loggerProvider = Globals::loggerProvider();
        $meterProvider = Globals::meterProvider();

        // Resolve service name: property → OTEL_SERVICE_NAME env var (Requirement 3.1, 3.2, 3.3)
        $scopeName = $this->resolveServiceName();

        $this->tracer = $tracerProvider->getTracer($scopeName);
        $this->logger = $loggerProvider->getLogger($scopeName);

        // Initialise metrics instruments
        $meter = $meterProvider->getMeter($scopeName);
        $this->requestCounter = $meter->createCounter(
            'http.server.requests',
            '{request}',
            'Total number of HTTP requests handled'
        );
        $this->errorCounter = $meter->createCounter(
            'http.server.errors',
            '{error}',
            'Total number of HTTP requests that resulted in a 5xx error'
        );
        $this->requestDuration = $meter->createHistogram(
            'http.server.duration',
            'ms',
            'Duration of HTTP requests in milliseconds'
        );

        // Register root span lifecycle
        $this->registerRootSpanLifecycle($app);

        // Register sub-components based on toggle properties (Requirement 12.1-12.5)
        $this->registerInstrumentation($app);
    }

    /**
     * Resolves the service name from the property or OTEL_SERVICE_NAME env var.
     */
    private function resolveServiceName(): string
    {
        if ($this->serviceName !== null && $this->serviceName !== '') {
            return $this->serviceName;
        }

        $envName = $this->getEnv('OTEL_SERVICE_NAME');
        if ($envName !== false && $envName !== '') {
            return $envName;
        }

        return 'yii2-app';
    }

    /**
     * Configures the OTEL SDK's internal log writer to forward messages to Yii::warning().
     */
    protected function configureOtelErrorHandler(): void
    {
        // Use a simple inline log writer that forwards to Yii::warning()
        Logging::setLogWriter(new class implements \OpenTelemetry\API\Behavior\Internal\LogWriter\LogWriterInterface {
            public function write($level, string $message, array $context): void
            {
                $source = $context['source'] ?? 'OpenTelemetry';
                unset($context['source']);

                $logMessage = $message;
                if (!empty($context)) {
                    if (isset($context['exception']) && $context['exception'] instanceof \Throwable) {
                        $logMessage .= ': ' . $context['exception']->getMessage();
                        unset($context['exception']);
                    }
                    if (!empty($context)) {
                        $logMessage .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES);
                    }
                }

                Yii::warning("[OTEL] $logMessage", $source);
            }
        });
    }

    /**
     * Registers root span lifecycle handlers for web and console applications.
     *
     * Web: EVENT_BEFORE_REQUEST creates root span, EVENT_AFTER_REQUEST ends it.
     * Console: EVENT_BEFORE_ACTION creates root span, EVENT_AFTER_ACTION ends it.
     *
     * Requirements: 2.1-2.8
     */
    private function registerRootSpanLifecycle(Application $app): void
    {
        if ($app instanceof WebApplication) {
            $app->on(WebApplication::EVENT_BEFORE_REQUEST, [$this, 'handleBeforeRequest']);
            $app->on(WebApplication::EVENT_AFTER_REQUEST, [$this, 'handleAfterRequest']);
        } elseif ($app instanceof ConsoleApplication) {
            $app->on(ConsoleApplication::EVENT_BEFORE_ACTION, [$this, 'handleBeforeConsoleAction']);
            $app->on(ConsoleApplication::EVENT_AFTER_ACTION, [$this, 'handleAfterConsoleAction']);
        }

        // Register shutdown function for unhandled exceptions (Requirement 2.7)
        register_shutdown_function([$this, 'handleShutdown']);
    }

    /**
     * Handles EVENT_BEFORE_REQUEST for web applications.
     *
     * Creates the root span named "HTTP {METHOD}", sets HTTP semantic attributes,
     * activates scope, and invokes SpanAttributeProviderInterface providers.
     *
     * Requirements: 2.1, 2.5, 2.6, 8.3
     */
    public function handleBeforeRequest(Event $event): void
    {
        $request = Yii::$app->getRequest();

        $method = $request->getMethod();
        $spanName = "HTTP {$method}";

        // Extract W3C traceparent/tracestate from incoming request headers
        // so this span becomes a child of any upstream trace
        $propagator = Globals::propagator();
        $parentContext = $propagator->extract($request->getHeaders()->toArray());

        $span = $this->tracer->spanBuilder($spanName)
            ->setParent($parentContext)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->setAttribute('http.method', $method)
            ->setAttribute('http.url', $request->getAbsoluteUrl())
            ->setAttribute('http.target', $request->getUrl())
            ->setAttribute('http.scheme', $request->getIsSecureConnection() ? 'https' : 'http')
            ->setAttribute('http.host', $request->getHostName() ?? '')
            ->startSpan();

        $scope = $span->activate();

        $this->rootSpan = $span;
        $this->rootScope = $scope;
        $this->requestStartTime = microtime(true);

        // Invoke SpanAttributeProviderInterface providers (Requirement 8.3, 8.4, 8.5)
        $this->applySpanAttributeProviders($span);
    }

    /**
     * Handles EVENT_AFTER_REQUEST for web applications.
     *
     * Sets http.status_code, ends the root span, and detaches scope.
     *
     * Requirements: 2.2, 2.6, 2.8
     */
    public function handleAfterRequest(Event $event): void
    {
        if ($this->rootSpan === null) {
            return;
        }

        $statusCode = 0;
        $response = Yii::$app->getResponse();
        if ($response instanceof Response) {
            $statusCode = $response->getStatusCode();
            $this->rootSpan->setAttribute('http.status_code', $statusCode);
        }

        // Record metrics
        $method = Yii::$app->getRequest()->getMethod();
        // Use the root span directly — RouteResolver has renamed it by now.
        // Span::getCurrent() can return a NonRecordingSpan which has no getName().
        $route = $this->rootSpan->getName();
        $metricAttributes = [
            'http.method' => $method,
            'http.route' => $route,
            'http.status_code' => (string) $statusCode,
        ];

        $this->requestCounter?->add(1, $metricAttributes);

        if ($statusCode >= 500) {
            $this->errorCounter?->add(1, $metricAttributes);
        }

        if ($this->requestStartTime !== null) {
            $durationMs = (microtime(true) - $this->requestStartTime) * 1000;
            $this->requestDuration?->record($durationMs, $metricAttributes);
            $this->requestStartTime = null;
        }

        $this->endRootSpan();
    }

    /**
     * Handles EVENT_BEFORE_ACTION for console applications.
     *
     * Creates the root span named "console/{route}" and activates scope.
     *
     * Requirements: 2.3, 2.5
     */
    public function handleBeforeConsoleAction(ActionEvent $event): void
    {
        $route = Yii::$app->requestedRoute;
        $spanName = "console/{$route}";

        $span = $this->tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_SERVER)
            ->startSpan();

        $scope = $span->activate();

        $this->rootSpan = $span;
        $this->rootScope = $scope;

        // Invoke SpanAttributeProviderInterface providers
        $this->applySpanAttributeProviders($span);
    }

    /**
     * Handles EVENT_AFTER_ACTION for console applications.
     *
     * Ends the root span and detaches scope.
     *
     * Requirements: 2.4, 2.8
     */
    public function handleAfterConsoleAction(ActionEvent $event): void
    {
        $this->endRootSpan();
    }

    /**
     * Shutdown function to handle unhandled exceptions.
     *
     * Records the exception on the root span, sets ERROR status, ends span,
     * and detaches scope.
     *
     * Requirement: 2.7
     */
    public function handleShutdown(): void
    {
        $error = error_get_last();

        if ($error !== null && $this->rootSpan !== null) {
            $errorTypes = [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE];
            if (in_array($error['type'], $errorTypes, true)) {
                $exception = new \ErrorException(
                    $error['message'],
                    0,
                    $error['type'],
                    $error['file'],
                    $error['line']
                );
                $this->rootSpan->recordException($exception);
                $this->rootSpan->setStatus(StatusCode::STATUS_ERROR, $error['message']);
                $this->endRootSpan();
            }
        }
    }

    /**
     * Invokes all registered SpanAttributeProviderInterface implementations
     * and sets their returned attributes on the given span.
     *
     * If a provider throws an exception, it is logged and the remaining
     * providers are still invoked.
     *
     * Requirements: 8.3, 8.4, 8.5
     */
    private function applySpanAttributeProviders(SpanInterface $span): void
    {
        foreach ($this->spanAttributeProviders as $providerConfig) {
            try {
                $provider = $this->resolveProvider($providerConfig);
                if ($provider === null) {
                    continue;
                }

                $attributes = $provider->getAttributes();
                foreach ($attributes as $key => $value) {
                    $span->setAttribute($key, $value);
                }
            } catch (\Throwable $e) {
                Yii::warning(
                    "SpanAttributeProvider error: {$e->getMessage()}",
                    'danvick\yii2\otel'
                );
            }
        }
    }

    /**
     * Resolves a SpanAttributeProviderInterface from a class name or component ID.
     *
     * @param string|array|SpanAttributeProviderInterface $config Class name, component ID, or instance
     * @return SpanAttributeProviderInterface|null
     */
    private function resolveProvider(mixed $config): ?SpanAttributeProviderInterface
    {
        if ($config instanceof SpanAttributeProviderInterface) {
            return $config;
        }

        if (is_string($config)) {
            // Try as Yii2 component ID first
            if (Yii::$app->has($config)) {
                $component = Yii::$app->get($config);
                if ($component instanceof SpanAttributeProviderInterface) {
                    return $component;
                }
            }

            // Try as class name
            if (class_exists($config)) {
                $instance = new $config();
                if ($instance instanceof SpanAttributeProviderInterface) {
                    return $instance;
                }
            }
        }

        return null;
    }

    /**
     * Ends the root span and detaches its scope.
     */
    private function endRootSpan(): void
    {
        if ($this->rootSpan !== null) {
            $this->rootSpan->end();
            $this->rootSpan = null;
        }

        if ($this->rootScope !== null) {
            $this->rootScope->detach();
            $this->rootScope = null;
        }
    }

    /**
     * Registers all instrumentation sub-components based on toggle properties.
     *
     * Requirements: 12.1-12.5
     */
    private function registerInstrumentation(Application $app): void
    {
        // Always register RouteResolver (Requirement 5.5)
        RouteResolver::register($app);

        // Conditionally register DB instrumentation (Requirement 12.2)
        if ($this->instrumentDb) {
            DbInstrumentation::register($this->tracer);
            DbInstrumentation::setKnownConnections($this->dbConnections);
        }

        // Conditionally register cache instrumentation (Requirement 12.3)
        if ($this->instrumentCache) {
            $this->registerCacheInstrumentation($app);
        }

        // Conditionally register queue instrumentation (Requirement 12.4)
        if ($this->instrumentQueue) {
            QueueInstrumentation::register($this->tracer);
        }

        // Conditionally register log bridge (Requirement 12.5)
        if ($this->logBridge) {
            $this->registerLogTarget($app);
        }

        // Conditionally register view rendering instrumentation (Requirement 12.6)
        if ($this->instrumentViews) {
            ViewInstrumentation::register($this->tracer);
        }

        // Conditionally register ActiveRecord instrumentation (Requirement 12.7)
        if ($this->instrumentAr) {
            ArInstrumentation::register($this->tracer);
        }

        // Conditionally register HTTP client instrumentation (Requirement 12.8)
        if ($this->instrumentHttpClient) {
            HttpClientInstrumentation::register($this->tracer);
        }

        // Conditionally register authentication instrumentation (Requirement 12.9)
        if ($this->instrumentAuth) {
            AuthInstrumentation::register($this->tracer);
        }

        // Conditionally register mail instrumentation (Requirement 12.10)
        if ($this->instrumentMail) {
            MailInstrumentation::register($this->tracer);
        }
    }

    /**
     * Registers the OtelLogTarget as a Yii2 log target.
     *
     * The SDK handles the no-op case when OTEL_LOGS_EXPORTER is not 'otlp',
     * so no guard is needed here — emit() becomes a no-op automatically.
     *
     * Requirement: 10.6
     */
    private function registerLogTarget(Application $app): void
    {
        $logTarget = new OtelLogTarget($this->logger);

        // In development, ship everything from INFO upward for full visibility.
        // In production the target defaults to WARNING | ERROR only.
        $env = $this->getEnv('YII_ENV');
        if ($env === 'dev' || $env === 'development') {
            $logTarget->setLevels(
                Logger::LEVEL_ERROR | Logger::LEVEL_WARNING | Logger::LEVEL_INFO
            );
        }

        $app->getLog()->targets[] = $logTarget;
    }

    /**
     * Replaces the app's cache component with InstrumentedCache if it's a yii\redis\Cache.
     *
     * Sets the static tracer on InstrumentedCache and reconfigures the cache component
     * to use the instrumented subclass while preserving the original configuration.
     *
     * Requirement: 7.7
     */
    private function registerCacheInstrumentation(Application $app): void
    {
        if (!$app->has('cache')) {
            return;
        }

        InstrumentedCache::setTracer($this->tracer);

        $cache = $app->get('cache', false);

        if ($cache === null) {
            // Cache not yet instantiated — check if the definition uses yii\redis\Cache
            // and swap the class to InstrumentedCache
            $definitions = $app->getComponents();
            if (isset($definitions['cache'])) {
                $def = $definitions['cache'];
                if (\is_array($def) && isset($def['class']) && $def['class'] === \yii\redis\Cache::class) {
                    $def['class'] = InstrumentedCache::class;
                    $app->set('cache', $def);
                }
            }
            return;
        }

        if ($cache instanceof \yii\redis\Cache && !($cache instanceof InstrumentedCache)) {
            // Already instantiated — re-register with InstrumentedCache, preserving
            // ALL relevant properties from the original instance so the replacement
            // connects to the same Redis backend with the same configuration.
            $config = [
                'class'           => InstrumentedCache::class,
                'redis'           => $cache->redis,           // preserve actual redis reference/config
                'keyPrefix'       => $cache->keyPrefix,
                'defaultDuration' => $cache->defaultDuration,
                'serializer'      => $cache->serializer,
            ];

            $app->set('cache', $config);
        }
    }

    /**
     * Returns the tracer instance.
     */
    public function getTracer(): ?TracerInterface
    {
        return $this->tracer;
    }

    /**
     * Returns the logger instance.
     */
    public function getLogger(): ?LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Returns the active root span (for testing purposes).
     */
    public function getRootSpan(): ?SpanInterface
    {
        return $this->rootSpan;
    }
}
