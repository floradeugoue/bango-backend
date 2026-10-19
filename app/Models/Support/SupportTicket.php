<?php

namespace App\Models\Support;

use App\Enums\Support\TicketCategory;
use App\Enums\Support\TicketPriority;
use App\Enums\Support\TicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class SupportTicket extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'assigned_to',
        'resolved_by_id',
        'subject',
        'category',
        'channel',
        'status',
        'priority',
        'resolution_category',
        'resolution_reason',
        'last_reply_at',
        'resolved_at',
        'closed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'priority' => TicketPriority::class,
            'last_reply_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportTicketMessage::class)->orderBy('created_at', 'asc');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(SupportTicketAttachment::class);
    }

    public function escalations(): HasMany
    {
        return $this->hasMany(SupportTicketEscalation::class)->orderByDesc('created_at');
    }

    /**
     * Scope par statut.
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    /**
     * Scope par utilisateur.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Génère une référence publique unique non prédictible.
     */
    public static function generateReference(): string
    {
        do {
            $ref = 'TIC-'.date('Y').'-'.strtoupper(Str::random(6));
        } while (static::where('reference', $ref)->exists());

        return $ref;
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    public function isClosed(): bool
    {
        return $this->status === TicketStatus::Closed;
    }

    public function canBeRepliedTo(): bool
    {
        return $this->status !== TicketStatus::Closed;
    }
}
