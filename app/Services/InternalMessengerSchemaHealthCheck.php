<?php

namespace App\Services;

use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Readiness gate for the internal messenger's canonical relational schema.
 *
 * The application and operator health command must not treat a partially
 * applied messenger migration as a usable deployment.
 */
class InternalMessengerSchemaHealthCheck
{
    public function assertReady(): bool
    {
        $requirements = [
            'conversations' => [
                'columns' => ['type', 'title', 'created_by', 'direct_key', 'last_message_preview', 'last_message_at', 'is_locked', 'created_at', 'updated_at'],
                'indexes' => ['conversations_direct_key_unique'],
            ],
            'conversation_participants' => [
                'columns' => ['conversation_id', 'user_id', 'role', 'last_read_message_id', 'read_at', 'muted_at', 'archived_at', 'joined_at', 'last_active_at', 'typing_until', 'created_at', 'updated_at'],
                'indexes' => [
                    'conversation_participants_conversation_id_user_id_unique',
                    'conversation_participants_user_id_archived_at_read_at_index',
                ],
            ],
            'conversation_messages' => [
                'columns' => ['conversation_id', 'user_id', 'reply_to_id', 'body', 'attachments', 'edited_at', 'deleted_at', 'created_at', 'updated_at'],
                'indexes' => ['conversation_messages_conversation_id_id_index'],
            ],
        ];

        foreach ($requirements as $table => $requirement) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Internal messenger schema is incomplete: {$table} table is missing.");
            }

            foreach ($requirement['columns'] as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Internal messenger schema is incomplete: {$table}.{$column} is missing.");
                }
            }

            foreach ($requirement['indexes'] as $index) {
                if (! Schema::hasIndex($table, $index)) {
                    throw new RuntimeException("Internal messenger schema is incomplete: {$index} is missing.");
                }
            }
        }

        return true;
    }
}
