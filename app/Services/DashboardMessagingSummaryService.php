<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class DashboardMessagingSummaryService
{
    public function __construct(private readonly MessengerAccessService $messengerAccess)
    {
    }

    /**
     * Count unread messages only in conversations the current user can actually open.
     *
     * @return array{available: bool, status: string, unread_messages: int|null}
     */
    public function snapshot(User $actor): array
    {
        if (! $this->messengerAccess->canUse($actor)) {
            return ['available' => false, 'status' => 'forbidden', 'unread_messages' => null];
        }

        foreach ([
            'conversation_participants' => ['user_id', 'conversation_id', 'archived_at', 'last_read_message_id'],
            'conversation_messages' => ['conversation_id', 'user_id', 'deleted_at'],
            'conversations' => ['id'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return ['available' => true, 'status' => 'unavailable', 'unread_messages' => null];
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return ['available' => true, 'status' => 'unavailable', 'unread_messages' => null];
                }
            }
        }

        try {
            $query = DB::table('conversation_participants as current_participant')
                ->join('conversations', 'conversations.id', '=', 'current_participant.conversation_id')
                ->join('conversation_messages', 'conversation_messages.conversation_id', '=', 'conversations.id')
                ->where('current_participant.user_id', $actor->id)
                ->whereNull('current_participant.archived_at')
                ->whereNull('conversation_messages.deleted_at')
                ->where('conversation_messages.user_id', '!=', $actor->id)
                ->whereRaw('conversation_messages.id > COALESCE(current_participant.last_read_message_id, 0)');

            // Mirror MessengerController::index: non-admins never count messages
            // from a conversation containing a non-admin outside their organization.
            if (! $actor->isAdmin()) {
                $query->whereNotExists(function ($foreignParticipants) use ($actor) {
                    $foreignParticipants->selectRaw('1')
                        ->from('conversation_participants as scoped_participant')
                        ->join('users as scoped_user', 'scoped_user.id', '=', 'scoped_participant.user_id')
                        ->whereColumn('scoped_participant.conversation_id', 'conversations.id')
                        ->where('scoped_user.role', '!=', User::ROLE_ADMIN)
                        ->where(function ($scope) use ($actor) {
                            $scope->whereNull('scoped_user.organization_id')
                                ->orWhere('scoped_user.organization_id', '!=', $actor->organization_id);
                        });
                });
            }

            return [
                'available' => true,
                'status' => 'available',
                'unread_messages' => (int) $query->count(),
            ];
        } catch (Throwable) {
            return ['available' => true, 'status' => 'unavailable', 'unread_messages' => null];
        }
    }
}
