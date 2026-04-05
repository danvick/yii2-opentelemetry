<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\SpanKind;
use OpenTelemetry\API\Trace\StatusCode;
use OpenTelemetry\API\Trace\TracerInterface;
use yii\base\Event;
use yii\db\Connection;

/**
 * Creates child spans for every DB query with connection metadata and sanitized SQL.
 *
 * Uses Yii2's Connection::EVENT_AFTER_OPEN to swap the commandClass on each
 * opened connection to InstrumentedCommand, which wraps execute() and
 * queryInternal() with OTEL spans.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7, 6.8, 6.9, 6.10, 17.1
 */
class DbInstrumentation
{
    /**
     * @var TracerInterface|null The tracer instance shared with InstrumentedCommand.
     */
    private static ?TracerInterface $tracer = null;

    /**
     * @var array Known DB connection component IDs to instrument.
     */
    private static array $knownConnections = ['db'];

    /**
     * Sets the known DB connection component IDs.
     *
     * Called by OtelBootstrap to configure which Yii2 connection components
     * should be instrumented.
     *
     * @param array $connections Array of Yii2 connection component IDs (e.g., ['db', 'masterDb', 'statsDb'])
     */
    public static function setKnownConnections(array $connections): void
    {
        self::$knownConnections = $connections;
    }

    /**
     * Registers the DB instrumentation by hooking into Connection::EVENT_AFTER_OPEN.
     *
     * When any Connection opens, its commandClass is replaced with
     * InstrumentedCommand so all subsequent commands are instrumented.
     *
     * Also swaps commandClass on any connections that were already opened
     * before this registration (e.g., masterDb opened during config loading).
     *
     * @param TracerInterface $tracer The OTEL tracer to use for creating spans
     */
    public static function register(TracerInterface $tracer): void
    {
        self::$tracer = $tracer;

        Event::on(
            Connection::class,
            Connection::EVENT_AFTER_OPEN,
            [static::class, 'handleAfterOpen']
        );

        // Swap commandClass on connections that are already open
        self::instrumentExistingConnections();
    }

    /**
     * Iterates known DB component names and swaps commandClass on any
     * that are already instantiated and open.
     */
    private static function instrumentExistingConnections(): void
    {
        $app = \Yii::$app;
        if ($app === null) {
            return;
        }

        foreach (self::$knownConnections as $name) {
            if (!$app->has($name, true)) {
                continue;
            }

            $connection = $app->get($name, false);
            if ($connection instanceof Connection && $connection->getIsActive()) {
                $connection->commandClass = InstrumentedCommand::class;
            }
        }
    }

    /**
     * Handles the EVENT_AFTER_OPEN event by setting the connection's
     * commandClass to InstrumentedCommand.
     *
     * @param Event $event The event triggered after a DB connection opens
     */
    public static function handleAfterOpen(Event $event): void
    {
        /** @var Connection $connection */
        $connection = $event->sender;
        $connection->commandClass = InstrumentedCommand::class;
    }

    /**
     * Returns the registered tracer instance.
     *
     * Used by InstrumentedCommand to create child spans.
     *
     * @return TracerInterface|null
     */
    public static function getTracer(): ?TracerInterface
    {
        return self::$tracer;
    }

    /**
     * Creates a child span for a DB operation, executes the callback, and ends the span.
     *
     * On failure, records the exception on the span and sets status to ERROR
     * before re-throwing.
     *
     * @param string $sql The SQL statement being executed
     * @param array $params The bound parameters
     * @param Connection $connection The DB connection
     * @param callable $callback The actual DB operation to execute
     * @return mixed The result of the callback
     * @throws \Throwable Re-throws any exception from the callback
     */
    public static function wrapWithSpan(string $sql, array $params, Connection $connection, callable $callback): mixed
    {
        $tracer = self::$tracer;
        if ($tracer === null) {
            return $callback();
        }

        if (!OtelHelpers::hasActiveSpan()) {
            return $callback();
        }

        $verb = OtelHelpers::extractSqlVerb($sql);
        $spanName = "DB {$verb}";

        $spanBuilder = $tracer->spanBuilder($spanName)
            ->setSpanKind(SpanKind::KIND_CLIENT)
            ->setAttribute('db.system', $connection->driverName)
            ->setAttribute('db.name', OtelHelpers::extractDbNameFromDsn($connection->dsn))
            ->setAttribute('db.connection_name', OtelHelpers::resolveConnectionName($connection, self::$knownConnections))
            ->setAttribute('db.statement', OtelHelpers::sanitizeSql($sql, $params));

        $span = $spanBuilder->startSpan();
        $scope = $span->activate();

        try {
            $result = $callback();
            $span->end();
            return $result;
        } catch (\Throwable $e) {
            $span->recordException($e);
            $span->setStatus(StatusCode::STATUS_ERROR, $e->getMessage());
            $span->end();
            throw $e;
        } finally {
            $scope->detach();
        }
    }
}
