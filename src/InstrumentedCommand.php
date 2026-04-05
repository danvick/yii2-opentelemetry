<?php

namespace danvick\yii2\otel;

use yii\db\Command;

/**
 * Extends yii\db\Command to wrap query execution with OTEL child spans.
 *
 * This class is set as the commandClass on connections via DbInstrumentation's
 * EVENT_AFTER_OPEN handler. It overrides execute() and queryInternal() to
 * create DB child spans with sanitized SQL, connection metadata, and error recording.
 *
 * Requirements: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6, 6.7
 */
class InstrumentedCommand extends Command
{
    /**
     * @inheritdoc
     *
     * Wraps the parent execute() (used for INSERT, UPDATE, DELETE, DDL)
     * with an OTEL child span.
     */
    public function execute()
    {
        $sql = $this->getSql();
        if ($sql === '' || $sql === null) {
            return parent::execute();
        }

        return DbInstrumentation::wrapWithSpan(
            $sql,
            $this->params,
            $this->db,
            fn() => parent::execute()
        );
    }

    /**
     * @inheritdoc
     *
     * Wraps the parent queryInternal() (used for SELECT queries via
     * queryAll(), queryOne(), queryColumn(), queryScalar()) with an OTEL child span.
     */
    protected function queryInternal($method, $fetchMode = null)
    {
        $sql = $this->getSql();
        if ($sql === '' || $sql === null) {
            return parent::queryInternal($method, $fetchMode);
        }

        return DbInstrumentation::wrapWithSpan(
            $sql,
            $this->params,
            $this->db,
            fn() => parent::queryInternal($method, $fetchMode)
        );
    }
}
