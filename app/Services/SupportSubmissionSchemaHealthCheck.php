<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Shared readiness gate for the idempotent support submission schema.
 *
 * Both the HTTP readiness endpoint and the operator health command must use
 * this check so a partially applied migration cannot pass either gate.
 */
class SupportSubmissionSchemaHealthCheck
{
    public function assertReady(): bool
    {
        $requirements = [
            'tickets' => [
                'columns' => ['submission_scope', 'submission_key', 'submission_fingerprint'],
                'index' => 'tickets_submission_scope_key_unique',
            ],
            'ticket_messages' => [
                'columns' => ['submission_scope', 'submission_key', 'submission_fingerprint'],
                'index' => 'ticket_messages_submission_unique',
            ],
        ];

        foreach ($requirements as $table => $requirement) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Support idempotency schema is incomplete: {$table} table is missing.");
            }

            foreach ($requirement['columns'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Support idempotency schema is incomplete: {$table}.{$column} is missing.");
                }
            }

            if (! Schema::hasIndex($table, $requirement['index'])) {
                throw new RuntimeException("Support idempotency schema is incomplete: {$requirement['index']} is missing.");
            }
        }

        return true;
    }
}
