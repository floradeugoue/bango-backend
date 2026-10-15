<?php

namespace App\Services\Visit;

use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Listing\ListingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class VisitSlotService
{
    public const DAYS_SHOWN = 5;

    public const VISIT_DURATION_MINUTES = 30;

    public const SLOT_STEP_MINUTES = 90;

    public const DEFAULT_MINIMUM_HOURS = 4;

    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Retourne la grille des créneaux disponibles pour une annonce sur les 5 prochains jours.
     * Respecte la capacité, le délai minimum de réservation et les verrous temporaires.
     *
     * @return array<int, array{
     *     id: string,
     *     date: string,
     *     weekday: string,
     *     day: string,
     *     available: bool,
     *     slots: array<int, array{
     *         id: string,
     *         time: string,
     *         start_at: string,
     *         end_at: string,
     *         capacity: int,
     *         booked_count: int,
     *         available: bool,
     *         is_mine: bool
     *     }>
     * }>
     */
    public function getAvailableDays(Listing $listing, ?User $prospect = null, ?Carbon $now = null): array
    {
        $at = $now ? $now->copy() : now();
        $earliestTime = $at->copy()->addHours(self::DEFAULT_MINIMUM_HOURS);
        $endDate = $at->copy()->addDays(self::DAYS_SHOWN);

        // 1. Récupérer les créneaux explicites en base de données pour la période
        $existingSlots = VisitSlot::where('listing_id', $listing->id)
            ->where('start_at', '>=', $at->copy()->startOfDay())
            ->where('start_at', '<=', $endDate->copy()->endOfDay())
            ->where('status', VisitSlotStatus::Open->value)
            ->with(['activeReservations'])
            ->get();

        // 2. Si aucun créneau n'est créé en base, générer les créneaux par défaut selon les règles BL-08
        if ($existingSlots->isEmpty()) {
            $this->ensureDefaultSlotsExist($listing, $at, self::DAYS_SHOWN);
            $existingSlots = VisitSlot::where('listing_id', $listing->id)
                ->where('start_at', '>=', $at->copy()->startOfDay())
                ->where('start_at', '<=', $endDate->copy()->endOfDay())
                ->where('status', VisitSlotStatus::Open->value)
                ->with(['activeReservations'])
                ->get();
        }

        // 3. Récupérer les réservations déjà posées par le prospect sur cette annonce
        $myReservedSlotIds = [];
        if ($prospect) {
            $myReservedSlotIds = VisitReservation::where('listing_id', $listing->id)
                ->where('prospect_id', $prospect->id)
                ->where(function ($q) {
                    $q->whereIn('status', [
                        VisitReservationStatus::Requested->value,
                        VisitReservationStatus::Confirmed->value,
                        VisitReservationStatus::Proposed->value,
                    ])->orWhere(function ($sub) {
                        $sub->where('status', VisitReservationStatus::PendingPayment->value)
                            ->where('lock_expires_at', '>', now());
                    });
                })
                ->pluck('slot_id')
                ->all();
        }

        // 4. Structurer les créneaux par jour
        $days = [];
        for ($i = 0; $i < self::DAYS_SHOWN; $i++) {
            $currentDate = $at->copy()->addDays($i);
            $dayKey = $currentDate->format('Y-m-d');

            $daySlots = $existingSlots->filter(function (VisitSlot $slot) use ($dayKey) {
                return $slot->start_at->format('Y-m-d') === $dayKey;
            })->sortBy('start_at');

            $formattedSlots = [];
            foreach ($daySlots as $slot) {
                $holdsCount = $slot->activeReservations->count();
                $isMine = in_array($slot->id, $myReservedSlotIds, true);
                $isFree = $slot->start_at->isAfter($earliestTime)
                    && ! $isMine
                    && $holdsCount < $slot->capacity;

                $formattedSlots[] = [
                    'id' => $slot->id,
                    'time' => $slot->start_at->format('H:i'),
                    'start_at' => $slot->start_at->toIso8601String(),
                    'end_at' => $slot->end_at->toIso8601String(),
                    'capacity' => $slot->capacity,
                    'booked_count' => $holdsCount,
                    'available' => $isFree,
                    'is_mine' => $isMine,
                ];
            }

            $days[] = [
                'id' => $dayKey,
                'date' => $dayKey,
                'weekday' => strtoupper($currentDate->locale('fr')->isoFormat('ddd')),
                'day' => $currentDate->format('d'),
                'available' => collect($formattedSlots)->contains('available', true),
                'slots' => array_values($formattedSlots),
            ];
        }

        return $days;
    }

    /**
     * Génère automatiquement les créneaux par défaut pour les jours à venir si l'annonce n'en a pas encore.
     */
    public function ensureDefaultSlotsExist(Listing $listing, Carbon $startDate, int $daysCount = 5): void
    {
        // Créneaux horaires par défaut : 09:30, 11:00, 14:00, 15:30, 17:00
        $defaultTimes = ['09:30', '11:00', '14:00', '15:30', '17:00'];

        for ($i = 0; $i < $daysCount; $i++) {
            $day = $startDate->copy()->addDays($i);

            // Pas de visites le dimanche (jour 0)
            if ($day->dayOfWeek === Carbon::SUNDAY) {
                continue;
            }

            foreach ($defaultTimes as $time) {
                [$hour, $minute] = explode(':', $time);
                $startAt = $day->copy()->setTime((int) $hour, (int) $minute, 0);
                $endAt = $startAt->copy()->addMinutes(self::VISIT_DURATION_MINUTES);

                VisitSlot::firstOrCreate(
                    [
                        'listing_id' => $listing->id,
                        'start_at' => $startAt,
                        'end_at' => $endAt,
                    ],
                    [
                        'owner_id' => $listing->owner_id,
                        'agent_id' => $listing->agent_id,
                        'capacity' => 1,
                        'booked_count' => 0,
                        'status' => VisitSlotStatus::Open,
                    ]
                );
            }
        }
    }

    /**
     * Création manuelle d'un créneau par un bailleur ou agent mandaté.
     */
    public function createCustomSlot(
        Listing $listing,
        User $actor,
        Carbon $startAt,
        Carbon $endAt,
        int $capacity = 1
    ): VisitSlot {
        if (! $this->listingService->canManage($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à gérer les créneaux de cette annonce.");
        }

        if ($startAt->isPast()) {
            throw ValidationException::withMessages([
                'start_at' => ['Le créneau ne peut pas être programmé dans le passé.'],
            ]);
        }

        if ($endAt->lte($startAt)) {
            throw ValidationException::withMessages([
                'end_at' => ['L\'heure de fin doit être postérieure à l\'heure de début.'],
            ]);
        }

        return VisitSlot::create([
            'listing_id' => $listing->id,
            'owner_id' => $listing->owner_id,
            'agent_id' => $listing->agent_id,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'capacity' => max(1, $capacity),
            'booked_count' => 0,
            'status' => VisitSlotStatus::Open,
        ]);
    }

    /**
     * Ferme ou annule un créneau. Empêche la fermeture si des réservations confirmées existent.
     */
    public function closeSlot(VisitSlot $slot, User $actor): VisitSlot
    {
        if (! $this->listingService->canManage($actor, $slot->listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à modifier ce créneau.");
        }

        // Vérifier si des réservations confirmées existent sur ce créneau
        $confirmedCount = $slot->reservations()
            ->where('status', VisitReservationStatus::Confirmed->value)
            ->count();

        if ($confirmedCount > 0) {
            throw ValidationException::withMessages([
                'slot' => ['Impossible de fermer ce créneau : des visites confirmées y sont déjà programmées.'],
            ]);
        }

        $slot->status = VisitSlotStatus::Closed;
        $slot->save();

        return $slot;
    }
}
