<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['type', 'title', 'created_by', 'direct_key', 'last_message_preview', 'last_message_at', 'is_locked'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'is_locked' => 'boolean'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function participants(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_participants')
            ->withPivot(['role', 'last_read_message_id', 'read_at', 'muted_at', 'archived_at', 'joined_at'])
            ->withTimestamps();
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->whereNull('deleted_at');
    }

    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participants', fn (Builder $q) => $q->where('users.id', $userId));
    }

    public function unreadCountFor(int $userId): int
    {
        $participant = $this->participants->firstWhere('id', $userId)?->pivot;
        if (! $participant) {
            return 0;
        }

        return $this->messages()
            ->where('id', '>', (int) ($participant->last_read_message_id ?? 0))
            ->where('user_id', '!=', $userId)
            ->count();
    }
}
