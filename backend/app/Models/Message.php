<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Message extends Model
{
    use HasFactory;

    protected $fillable = [
        'sender_id',
        'recipient_id',
        'parent_message_id',
        'subject',
        'body',
        'read_at',
        'deleted_by_sender',
        'deleted_by_recipient',
        'is_announcement',
        'broadcast_id',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'deleted_by_sender' => 'boolean',
            'deleted_by_recipient' => 'boolean',
            'is_announcement' => 'boolean',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<User, $this> */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function parentMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_message_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_message_id')->orderBy('created_at');
    }

    public function getIsReadAttribute(): bool
    {
        return $this->read_at !== null;
    }

    /** @param Builder<Message> $query */
    public function scopeAnnouncements(Builder $query): Builder
    {
        return $query->where('is_announcement', true);
    }

    /** @param Builder<Message> $query */
    public function scopeRegular(Builder $query): Builder
    {
        return $query->where('is_announcement', false);
    }

    /** Messages encore visibles pour l'utilisateur (suppression logique par côté). */
    public function scopeVisibleToUser(Builder $query, int $userId): Builder
    {
        return $query->where(function (Builder $query) use ($userId): void {
            $query
                ->where(function (Builder $query) use ($userId): void {
                    $query
                        ->where('sender_id', $userId)
                        ->where('deleted_by_sender', false);
                })
                ->orWhere(function (Builder $query) use ($userId): void {
                    $query
                        ->where('recipient_id', $userId)
                        ->where('deleted_by_recipient', false);
                });
        });
    }

    public function isHiddenForUser(int $userId): bool
    {
        if ($this->sender_id === $userId && $this->deleted_by_sender) {
            return true;
        }

        if ($this->recipient_id === $userId && $this->deleted_by_recipient) {
            return true;
        }

        return false;
    }
}
