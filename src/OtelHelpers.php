<?php

namespace danvick\yii2\otel;

use OpenTelemetry\API\Trace\Span;
use yii\db\Connection;
use yii\log\Logger;

/**
 * Pure static helper functions for OpenTelemetry instrumentation.
 *
 * All methods are stateless and side-effect-free, making them easy to test
 * with property-based testing.
 */
class OtelHelpers
{
    /**
     * Builds a route-based span name for web requests.
     *
     * Returns `{moduleId}/{controllerId}/{actionId}` when moduleId is present,
     * or `{controllerId}/{actionId}` when moduleId is null.
     *
     * @param string|null $moduleId Module ID (e.g., 'academics') or null if no module
     * @param string $controllerId Controller ID (e.g., 'exams', 'default')
     * @param string $actionId Action ID (e.g., 'index', 'view')
     * @return string The formatted route name
     */
    public static function buildRouteName(?string $moduleId, string $controllerId, string $actionId): string
    {
        if ($moduleId !== null) {
            return "{$moduleId}/{$controllerId}/{$actionId}";
        }

        return "{$controllerId}/{$actionId}";
    }

    /**
     * Builds a route-based span name for console commands.
     *
     * @param string $route The console route (e.g., 'migrate/up')
     * @return string The formatted console route name (e.g., 'console/migrate/up')
     */
    public static function buildConsoleRouteName(string $route): string
    {
        return "console/{$route}";
    }

    /**
     * Extracts the SQL verb (first word) from a SQL statement.
     *
     * @param string $sql The SQL statement
     * @return string The uppercase SQL verb (e.g., 'SELECT', 'INSERT', 'UPDATE', 'DELETE')
     */
    public static function extractSqlVerb(string $sql): string
    {
        $trimmed = ltrim($sql);
        $spacePos = \strpos($trimmed, ' ');

        if ($spacePos === false) {
            return \strtoupper($trimmed);
        }

        return \strtoupper(\substr($trimmed, 0, $spacePos));
    }

    /**
     * Sanitizes a SQL statement by replacing bound parameter values with `?` placeholders.
     *
     * The params array has keys like `:param0` and values are the bound values.
     * Occurrences of the param keys in the SQL are replaced with `?`.
     *
     * @param string $sql The SQL statement with named parameter placeholders
     * @param array $params Associative array of parameter names to values (e.g., [':param0' => 'value'])
     * @return string The sanitized SQL with `?` placeholders
     */
    public static function sanitizeSql(string $sql, array $params): string
    {
        if (empty($params)) {
            return $sql;
        }

        // Sort keys by length descending to avoid partial replacements
        // e.g., :param10 should be replaced before :param1
        $keys = array_keys($params);
        usort($keys, fn(string $a, string $b): int => \strlen($b) - \strlen($a));

        foreach ($keys as $key) {
            $sql = str_replace($key, '?', $sql);
        }

        return $sql;
    }

    /**
     * Extracts the database name from a MySQL DSN string.
     *
     * Parses DSN strings like `mysql:host=localhost;port=3306;dbname=mydb`
     * and returns the `dbname` value.
     *
     * @param string $dsn The MySQL DSN string
     * @return string The database name, or empty string if not found
     */
    public static function extractDbNameFromDsn(string $dsn): string
    {
        if (preg_match('/dbname=([^;]+)/', $dsn, $matches)) {
            return $matches[1];
        }

        return '';
    }

    /**
     * Resolves the Yii2 application component name for a database connection.
     *
     * Compares the given connection object by identity against the app's
     * known connection components.
     *
     * @param Connection $connection The database connection to identify
     * @param array $knownConnections List of Yii2 connection component IDs to check (default: ['db'])
     * @return string The component name or 'unknown'
     */
    public static function resolveConnectionName(Connection $connection, array $knownConnections = ['db']): string
    {
        $app = \Yii::$app;

        foreach ($knownConnections as $name) {
            if ($app->has($name) && $app->get($name) === $connection) {
                return $name;
            }
        }

        return 'unknown';
    }

    /**
     * Maps a Yii2 Logger level constant to an OpenTelemetry severity string.
     *
     * Mapping:
     * - Logger::LEVEL_ERROR   → 'ERROR'
     * - Logger::LEVEL_WARNING → 'WARN'
     * - Logger::LEVEL_INFO    → 'INFO'
     * - Logger::LEVEL_TRACE   → 'DEBUG'
     * - Logger::LEVEL_PROFILE → 'DEBUG'
     *
     * @param int $level The Yii2 Logger level constant
     * @return string The OTEL severity string
     */
    public static function mapYiiLogLevel(int $level): string
    {
        return match ($level) {
            Logger::LEVEL_ERROR => 'ERROR',
            Logger::LEVEL_WARNING => 'WARN',
            Logger::LEVEL_INFO => 'INFO',
            Logger::LEVEL_TRACE => 'DEBUG',
            Logger::LEVEL_PROFILE => 'DEBUG',
            default => 'UNSPECIFIED',
        };
    }

    /**
     * Extracts the short class name from a fully-qualified class name.
     *
     * Returns the last segment after the final `\` separator.
     * e.g., `common\jobs\ExamClassRankingJob` → `ExamClassRankingJob`
     *
     * @param string $fqcn The fully-qualified class name
     * @return string The short class name
     */
    public static function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        if ($pos === false) {
            return $fqcn;
        }

        return \substr($fqcn, $pos + 1);
    }

    /**
     * Checks whether there is a valid active span in the current context.
     *
     * Returns `true` when a real recording span is active (e.g., an HTTP request
     * root span or a queue job span), and `false` when no span is active (the
     * default non-recording span with an invalid context).
     *
     * Used as a guard to prevent creating orphaned root spans in contexts
     * without a parent span (console commands, queue workers, cron jobs).
     *
     * @return bool Whether a valid active span exists
     */
    public static function hasActiveSpan(): bool
    {
        return Span::getCurrent()->getContext()->isValid();
    }
}
