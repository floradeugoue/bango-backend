<?php

namespace App\Models\Delegation;

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $bailleur_id
 * @property int $agent_id
 * @property string $listing_id
 * @property DelegationStatus $status
 * @property array<string>|null $capabilities
 * @property Carbon $invited_at
 * @property Carbon|null $answered_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $revoked_at
 * @property int|null $revoked_by_id
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $bailleur
 * @property-read User $agent
 * @property-read Listing $listing
 * @property-read User|null $revokedBy
 */
class Delegation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'delegations';

    protected $fillable = [
        'id',
        'bailleur_id',
        'agent_id',
        'listing_id',
        'status',
        'capabilities',
        'invited_at',
        'answered_at',
        'expires_at',
        'revoked_at',
        'revoked_by_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => DelegationStatus::class,
            'capabilities' => 'array',
            'invited_at' => 'datetime',
            'answered_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function bailleur(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bailleur_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by_id');
    }

    public function hasCapability(string|DelegationCapability $capability): bool
    {
        $capValue = $capability instanceof DelegationCapability ? $capability->value : (string) $capability;
        $caps = $this->capabilities ?? [];

        return in_array($capValue, $caps, true);
    }

    public function isOperational(): bool
    {
        if ($this->status !== DelegationStatus::Active) {
            return false;
        }

        if ($this->revoked_at !== null) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function isExpired(): bool
    {
        if ($this->status === DelegationStatus::Expired) {
            return true;
        }

        if ($this->status === DelegationStatus::Pending && $this->expires_at && $this->expires_at->isPast()) {
            return true;
        }

        return false;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', DelegationStatus::Active->value)
            ->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', DelegationStatus::Pending->value)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });
    }

    public function scopeForAgent(Builder $query, int $agentId): Builder
    {
        return $query->where('agent_id', $agentId);
    }

    public function scopeForLandlord(Builder $query, int $landlordId): Builder
    {
        return $query->where('bailleur_id', $landlordId);
    }
}
