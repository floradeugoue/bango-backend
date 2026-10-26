<?php

namespace App\Models\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $conversation_id
 * @property int $user_id
 * @property ConversationMemberRole $role
 * @property bool $is_muted
 * @property string|null $last_read_message_id
 * @property Carbon|null $last_read_at
 * @property Carbon $joined_at
 * @property Carbon|null $left_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Conversation $conversation
 * @property-read User $user
 */
class ConversationMember extends Model
{
    use HasFactory;

    protected $table = 'conversation_members';

    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'is_muted',
        'last_read_message_id',
        'last_read_at',
        'last_delivered_at',
        'joined_at',
        'left_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => ConversationMemberRole::class,
            'is_muted' => 'boolean',
            'last_read_at' => 'datetime',
            'last_delivered_at' => 'datetime',
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Conversation, $this>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Check if this member is an administrator.
     */
    public function isAdmin(): bool
    {
        return $this->role === ConversationMemberRole::Admin;
    }

    /**
     * Mark conversation as read up to a specific message ID.
     */
    public function markAsRead(?string $messageId = null): void
    {
        $now = Carbon::now();
        $this->update([
            'last_read_message_id' => $messageId ?? $this->last_read_message_id,
            'last_read_at' => $now,
            'last_delivered_at' => $this->last_delivered_at ? max($this->last_delivered_at, $now) : $now,
        ]);
    }

    /**
     * Mark conversation messages as delivered to this member's device.
     */
    public function markAsDelivered(): void
    {
        $this->update([
            'last_delivered_at' => Carbon::now(),
        ]);
    }
}
