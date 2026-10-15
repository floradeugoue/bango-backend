<?php

namespace App\Models\Visit;

use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $listing_id
 * @property int $owner_id
 * @property int|null $agent_id
 * @property Carbon $start_at
 * @property Carbon $end_at
 * @property int $capacity
 * @property int $booked_count
 * @property VisitSlotStatus $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Listing $listing
 * @property-read User $owner
 * @property-read User|null $agent
 */
class VisitSlot extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'visit_slots';

    protected $fillable = [
        'listing_id',
        'owner_id',
        'agent_id',
        'start_at',
        'end_at',
        'capacity',
        'booked_count',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'capacity' => 'integer',
            'booked_count' => 'integer',
            'status' => VisitSlotStatus::class,
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(VisitReservation::class, 'slot_id');
    }

    /**
     * Réservations actives qui retiennent actuellement une place sur le créneau.
     * Prend en compte les réservations confirmées/demandées et les verrous temporaires non expirés.
     */
    public function activeReservations(): HasMany
    {
        return $this->hasMany(VisitReservation::class, 'slot_id')
            ->where(function (Builder $query) {
                $query->whereIn('status', [
                    VisitReservationStatus::Requested->value,
                    VisitReservationStatus::Confirmed->value,
                    VisitReservationStatus::Proposed->value,
                ])->orWhere(function (Builder $sub) {
                    $sub->where('status', VisitReservationStatus::PendingPayment->value)
                        ->where('lock_expires_at', '>', now());
                });
            });
    }

    /**
     * Nombre réel de places actuellement retenues (confirmées ou sous verrou actif).
     */
    public function activeHoldsCount(): int
    {
        return $this->activeReservations()->count();
    }

    /**
     * Vérifie si le créneau a encore de la capacité disponible pour une nouvelle réservation.
     */
    public function hasAvailableCapacity(): bool
    {
        if ($this->status !== VisitSlotStatus::Open) {
            return false;
        }

        if ($this->start_at->isPast()) {
            return false;
        }

        return $this->activeHoldsCount() < $this->capacity;
    }

    /**
     * Scope pour les créneaux futurs et ouverts.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', VisitSlotStatus::Open->value)
            ->where('start_at', '>', now());
    }

    /**
     * Scope pour filtrer par annonce.
     */
    public function scopeForListing(Builder $query, string $listingId): Builder
    {
        return $query->where('listing_id', $listingId);
    }
}
