<?php

namespace App\Models\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property ConversationType $type
 * @property string|null $title
 * @property string|null $avatar_url
 * @property string|null $listing_id
 * @property int $created_by_id
 * @property Carbon|null $last_message_at
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $creator
 * @property-read Listing|null $listing
 * @property-read Collection<int, ConversationMember> $members
 * @property-read Collection<int, User> $users
 * @property-read Collection<int, Message> $messages
 * @property-read Message|null $latestMessage
 */
class Conversation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'conversations';

    protected $fillable = [
        'id',
        'type',
        'status',
        'page_id',
        'title',
        'avatar_url',
        'listing_id',
        'created_by_id',
        'last_message_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /**
     * @return BelongsTo<Page, $this>
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }

    /**
     * @return BelongsTo<Listing, $this>
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    /**
     * @return HasMany<ConversationMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(ConversationMember::class, 'conversation_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversation_members', 'conversation_id', 'user_id')
            ->withPivot(['role', 'is_muted', 'last_read_message_id', 'last_read_at', 'joined_at', 'left_at'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<Message, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class, 'conversation_id');
    }

    /**
     * @return HasOne<Message, $this>
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class, 'conversation_id')->latestOfMany('created_at');
    }

    /**
     * Scope for a specific user membership.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('members', function (Builder $memberQuery) use ($userId): void {
            $memberQuery->where('user_id', $userId)->whereNull('left_at');
        });
    }

    /**
     * Scope for direct threads.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeDirect(Builder $query): Builder
    {
        return $query->where('type', ConversationType::Direct);
    }

    /**
     * Scope for group threads.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeGroup(Builder $query): Builder
    {
        return $query->where('type', ConversationType::Group);
    }

    /**
     * Scope pour les conversations normales actives (non en attente de demande).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'active')->orWhereNull('status');
        });
    }

    /**
     * Scope pour les demandes de messages en attente.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeRequests(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isActive(): bool
    {
        return $this->status === 'active' || $this->status === null;
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if a user is an active member.
     */
    public function isMember(int $userId): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Check if a user is an admin of the conversation.
     */
    public function isAdmin(int $userId): bool
    {
        return $this->members()
            ->where('user_id', $userId)
            ->where('role', ConversationMemberRole::Admin)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Retrieve the member record for a given user.
     */
    public function member(int $userId): ?ConversationMember
    {
        /** @var ConversationMember|null */
        return $this->members()
            ->where('user_id', $userId)
            ->first();
    }

    /**
     * For 1-on-1 conversations, get the peer member.
     */
    public function otherMember(int $userId): ?ConversationMember
    {
        /** @var ConversationMember|null */
        return $this->members()
            ->where('user_id', '!=', $userId)
            ->first();
    }
}
