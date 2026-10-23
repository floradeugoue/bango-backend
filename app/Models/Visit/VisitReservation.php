<?php

namespace App\Models\Visit;

use App\Enums\Visit\VisitDeclineReason;
use App\Enums\Visit\VisitReservationStatus;
use App\Models\Listing\Listing;
use App\Models\Payment\PaymentTransaction;
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
 * @property string $listing_id
 * @property string $slot_id
 * @property int $prospect_id
 * @property int $owner_id
 * @property int|null $agent_id
 * @property VisitReservationStatus $status
 * @property Carbon|null $lock_expires_at
 * @property float $fee
 * @property string $currency
 * @property string|null $message
 * @property string|null $payment_reference
 * @property int|null $transaction_id
 * @property Carbon|null $paid_at
 * @property Carbon|null $answered_at
 * @property string|null $proposal_slot_id
 * @property VisitDeclineReason|string|null $decline_reason
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property Carbon|null $refunded_at
 * @property float|null $refund_amount
 * @property string|null $refund_channel
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Listing $listing
 * @property-read VisitSlot $slot
 * @property-read User $prospect
 * @property-read User $owner
 * @property-read User|null $agent
 * @property-read VisitSlot|null $proposalSlot
 * @property-read PaymentTransaction|null $paymentTransaction
 */
class VisitReservation extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'visit_reservations';

    protected $fillable = [
        'listing_id',
        'slot_id',
        'prospect_id',
        'owner_id',
        'agent_id',
        'status',
        'lock_expires_at',
        'fee',
        'fee_amount',
        'currency',
        'message',
        'prospect_message',
        'payment_reference',
        'transaction_id',
        'payment_transaction_id',
        'prospect_phone',
        'pricing_snapshot',
        'paid_at',
        'answered_at',
        'proposal_slot_id',
        'decline_reason',
        'cancelled_at',
        'cancellation_reason',
        'refunded_at',
        'refund_amount',
        'refund_channel',
        'metadata',
    ];

    public function getFeeAmountAttribute(): float
    {
        return (float) ($this->attributes['fee'] ?? 0);
    }

    public function setFeeAmountAttribute(mixed $value): void
    {
        $this->attributes['fee'] = $value;
    }

    public function getPaymentTransactionIdAttribute(): ?int
    {
        return isset($this->attributes['transaction_id']) ? (int) $this->attributes['transaction_id'] : null;
    }

    public function setPaymentTransactionIdAttribute(mixed $value): void
    {
        $this->attributes['transaction_id'] = $value;
    }

    public function getProspectMessageAttribute(): ?string
    {
        return $this->attributes['message'] ?? null;
    }

    public function setProspectMessageAttribute(mixed $value): void
    {
        $this->attributes['message'] = $value;
    }

    public function getProspectPhoneAttribute(): ?string
    {
        return $this->metadata['prospect_phone'] ?? null;
    }

    public function setProspectPhoneAttribute(mixed $value): void
    {
        $meta = $this->metadata ?? [];
        $meta['prospect_phone'] = $value;
        $this->metadata = $meta;
    }

    public function getPricingSnapshotAttribute(): ?array
    {
        return $this->metadata['pricing_snapshot'] ?? null;
    }

    public function setPricingSnapshotAttribute(mixed $value): void
    {
        $meta = $this->metadata ?? [];
        $meta['pricing_snapshot'] = $value;
        $this->metadata = $meta;
    }

    protected function casts(): array
    {
        return [
            'status' => VisitReservationStatus::class,
            'decline_reason' => VisitDeclineReason::class,
            'fee' => 'decimal:2',
            'refund_amount' => 'decimal:2',
            'lock_expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'answered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'refunded_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id')->withTrashed();
    }

    public function slot(): BelongsTo
    {
        return $this->belongsTo(VisitSlot::class, 'slot_id');
    }

    public function proposalSlot(): BelongsTo
    {
        return $this->belongsTo(VisitSlot::class, 'proposal_slot_id');
    }

    public function prospect(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prospect_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    /**
     * Vérifie si le verrou temporaire de 15 minutes est actuellement actif.
     */
    public function isLockActive(): bool
    {
        return $this->status === VisitReservationStatus::PendingPayment
            && $this->lock_expires_at !== null
            && $this->lock_expires_at->isFuture();
    }

    /**
     * Détermine si cette réservation donne accès à l'adresse exacte et aux consignes privées.
     */
    public function grantsPrivateAddressAccess(): bool
    {
        return $this->status->grantsPrivateAddressAccess();
    }

    /**
     * Règle IM-14 : Détermine si l'annulation donne droit à un remboursement.
     * Remboursable si la visite n'était pas encore confirmée (requested, proposed),
     * ou si l'annulation intervient plus de 24h avant le début du créneau.
     */
    public function isEligibleForRefund(?Carbon $at = null): bool
    {
        if (in_array($this->status, [VisitReservationStatus::Requested, VisitReservationStatus::Proposed], true)) {
            return true;
        }

        if ($this->status === VisitReservationStatus::Confirmed) {
            $checkTime = $at ?? now();

            // Au moins 24 heures avant l'heure de début du créneau
            return $checkTime->diffInHours($this->slot->start_at, false) >= 24;
        }

        return false;
    }

    public function scopeForProspect(Builder $query, int $userId): Builder
    {
        return $query->where('prospect_id', $userId);
    }

    public function scopeForLandlord(Builder $query, int $userId): Builder
    {
        return $query->where('owner_id', $userId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            VisitReservationStatus::Requested->value,
            VisitReservationStatus::Confirmed->value,
            VisitReservationStatus::Proposed->value,
        ]);
    }
}
