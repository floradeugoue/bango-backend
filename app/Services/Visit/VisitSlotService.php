<?php

namespace App\Services\Visit;

use App\Enums\Visit\VisitReservationStatus;
use App\Enums\Visit\VisitSlotStatus;
use App\Models\Geo\City;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Services\Listing\ListingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $minimumHours = (int) ($listing->attributes['minimum_hours'] ?? self::DEFAULT_MINIMUM_HOURS);
        $earliestTime = $at->copy()->addHours($minimumHours);
        $endDate = $at->copy()->addDays(self::DAYS_SHOWN);

        // 1. Récupérer les créneaux explicites en base de données pour la période
        $existingSlots = VisitSlot::where('listing_id', $listing->id)
            ->where('start_at', '>=', $at->copy()->startOfDay())
            ->where('start_at', '<=', $endDate->copy()->endOfDay())
            ->where('status', VisitSlotStatus::Open->value)
            ->with(['activeReservations'])
            ->get();

        // 2. Si aucun créneau n'est créé en base et que le bailleur utilise l'agenda, générer les créneaux par défaut selon les règles BL-08
        if ($existingSlots->isEmpty() && $listing->usesAgenda()) {
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
     * Résout le fuseau horaire de l'annonce d'après sa ville ou sa devise (IM-07).
     */
    public function resolveListingTimezone(Listing $listing): string
    {
        if (! empty($listing->city)) {
            $cityName = strtolower(trim($listing->city));

            if (in_array($cityName, ['douala', 'yaounde', 'yaoundé', 'bafoussam', 'garoua', 'kribi', 'limbe', 'maroua', 'bamenda'], true)) {
                return 'Africa/Douala';
            }
            if (in_array($cityName, ['abidjan', 'yamoussoukro', 'bouake', 'bouaké', 'san pedro', 'korhogo'], true)) {
                return 'Africa/Abidjan';
            }
            if (in_array($cityName, ['dakar', 'thies', 'thiès', 'saint-louis', 'touba', 'mbour', 'ziguinchor'], true)) {
                return 'Africa/Dakar';
            }
            if (in_array($cityName, ['libreville', 'port-gentil', 'franceville', 'oyem'], true)) {
                return 'Africa/Libreville';
            }
            if (in_array($cityName, ['lagos', 'abuja', 'ibadan', 'kano', 'port harcourt'], true)) {
                return 'Africa/Lagos';
            }
            if (in_array($cityName, ['accra', 'kumasi', 'tamale'], true)) {
                return 'Africa/Accra';
            }
            if (in_array($cityName, ['paris', 'lyon', 'marseille', 'bordeaux'], true)) {
                return 'Europe/Paris';
            }

            $city = City::with('country')->where('name', 'ilike', $listing->city)->first();
            if ($city && $city->country) {
                return match (strtoupper($city->country->iso2 ?? $city->country->code ?? '')) {
                    'CM' => 'Africa/Douala',
                    'CI' => 'Africa/Abidjan',
                    'SN' => 'Africa/Dakar',
                    'GA' => 'Africa/Libreville',
                    'NG' => 'Africa/Lagos',
                    'GH' => 'Africa/Accra',
                    'FR' => 'Europe/Paris',
                    default => 'Africa/Douala',
                };
            }
        }

        if (! empty($listing->currency)) {
            return match (strtoupper($listing->currency)) {
                'XOF' => 'Africa/Abidjan',
                'XAF' => 'Africa/Douala',
                'EUR' => 'Europe/Paris',
                'NGN' => 'Africa/Lagos',
                'GHS' => 'Africa/Accra',
                default => 'Africa/Douala',
            };
        }

        return 'Africa/Douala';
    }

    /**
     * Génère automatiquement les créneaux par défaut pour les jours à venir si l'annonce n'en a pas encore.
     */
    public function ensureDefaultSlotsExist(Listing $listing, Carbon $startDate, int $daysCount = 5): void
    {
        // Créneaux horaires par défaut : 09:30, 11:00, 14:00, 15:30, 17:00
        $defaultTimes = ['09:30', '11:00', '14:00', '15:30', '17:00'];
        $timezone = $this->resolveListingTimezone($listing);

        for ($i = 0; $i < $daysCount; $i++) {
            $day = $startDate->copy()->setTimezone($timezone)->addDays($i);

            // Pas de visites le dimanche (jour 0)
            if ($day->dayOfWeek === Carbon::SUNDAY) {
                continue;
            }

            foreach ($defaultTimes as $time) {
                [$hour, $minute] = explode(':', $time);
                // Créer le créneau dans le fuseau du pays de l'annonce, puis stocker en UTC
                $startAt = $day->copy()->setTime((int) $hour, (int) $minute, 0)->utc();
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
        if (! $this->listingService->canManageVisits($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à gérer les créneaux de cette annonce (capacité 'visits' requise).");
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
        if (! $this->listingService->canManageVisits($actor, $slot->listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à modifier ce créneau (capacité 'visits' requise).");
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

    /**
     * Configuration globale ou synchronisation par lot des créneaux (BL-04 / BL-08).
     *
     * @param  array{
     *     slots?: array<int, array{
     *         start_at: string,
     *         end_at: string,
     *         capacity?: int
     *     }>,
     *     days?: array<int, array{
     *         day: int,
     *         from: string,
     *         to: string,
     *         closed?: bool
     *     }>,
     *     slot_duration_minutes?: int,
     *     horizon_days?: int
     * }  $payload
     * @return array<string, mixed>
     */
    public function updateSlots(Listing $listing, User $actor, array $payload): array
    {
        if (! $this->listingService->canManageVisits($actor, $listing)) {
            throw new AuthorizationException("Vous n'êtes pas autorisé à gérer les créneaux de cette annonce (capacité 'visits' requise).");
        }

        return DB::transaction(function () use ($listing, $payload) {
            $createdSlots = [];

            // Sauvegarder les règles de créneaux BL-08 sur l'annonce si spécifiées
            $attrs = $listing->attributes ?? [];
            if (isset($payload['minimum_hours'])) {
                $attrs['minimum_hours'] = (int) $payload['minimum_hours'];
            }
            if (isset($payload['grouped'])) {
                $attrs['grouped'] = (bool) $payload['grouped'];
            }
            if (isset($payload['group_size'])) {
                $attrs['group_size'] = (int) $payload['group_size'];
            }
            $listing->attributes = $attrs;
            $listing->save();

            $defaultCapacity = ! empty($payload['grouped']) ? max(1, (int) ($payload['group_size'] ?? 5)) : 1;

            // 1. Mise à jour explicite par liste de créneaux
            if (! empty($payload['slots']) && is_array($payload['slots'])) {
                $slotsInput = collect($payload['slots'])->sortBy('start_at')->values();

                for ($i = 0; $i < $slotsInput->count(); $i++) {
                    $start = Carbon::parse($slotsInput[$i]['start_at']);
                    $end = Carbon::parse($slotsInput[$i]['end_at']);

                    if ($start->isPast()) {
                        throw ValidationException::withMessages([
                            "slots.{$i}.start_at" => ['Un créneau ne peut pas être programmé dans le passé.'],
                        ]);
                    }

                    if ($end->lte($start)) {
                        throw ValidationException::withMessages([
                            "slots.{$i}.end_at" => ['L\'heure de fin doit être postérieure à l\'heure de début.'],
                        ]);
                    }

                    if ($i > 0) {
                        $prevEnd = Carbon::parse($slotsInput[$i - 1]['end_at']);
                        if ($start->lt($prevEnd)) {
                            throw ValidationException::withMessages([
                                "slots.{$i}" => ['Deux créneaux soumis se chevauchent dans le planning.'],
                            ]);
                        }
                    }

                    $capacity = (int) ($slotsInput[$i]['capacity'] ?? $defaultCapacity);

                    $slot = VisitSlot::firstOrCreate(
                        [
                            'listing_id' => $listing->id,
                            'start_at' => $start,
                            'end_at' => $end,
                        ],
                        [
                            'owner_id' => $listing->owner_id,
                            'agent_id' => $listing->agent_id,
                            'capacity' => max(1, $capacity),
                            'booked_count' => 0,
                            'status' => VisitSlotStatus::Open,
                        ]
                    );

                    if ($slot->status !== VisitSlotStatus::Open) {
                        $slot->status = VisitSlotStatus::Open;
                        $slot->capacity = max(1, $capacity);
                        $slot->save();
                    }

                    $createdSlots[] = $slot;
                }
            }

            // 2. Mise à jour selon une grille récurrente (jours et horaires)
            if (! empty($payload['days']) && is_array($payload['days'])) {
                $horizonDays = max(1, min(30, (int) ($payload['horizon_days'] ?? 7)));
                $slotDuration = max(15, min(120, (int) ($payload['slot_duration_minutes'] ?? self::VISIT_DURATION_MINUTES)));
                $scheduleByDay = collect($payload['days'])->keyBy('day');
                $timezone = $this->resolveListingTimezone($listing);

                for ($d = 0; $d < $horizonDays; $d++) {
                    $date = now()->setTimezone($timezone)->addDays($d);
                    $dayOfWeek = $date->dayOfWeek;

                    if (! $scheduleByDay->has($dayOfWeek)) {
                        continue;
                    }

                    $dayRule = $scheduleByDay->get($dayOfWeek);
                    if (! empty($dayRule['closed'])) {
                        continue;
                    }

                    $from = $dayRule['from'] ?? '09:00';
                    $to = $dayRule['to'] ?? '18:00';

                    [$fromH, $fromM] = explode(':', $from);
                    [$toH, $toM] = explode(':', $to);

                    $cursor = $date->copy()->setTime((int) $fromH, (int) $fromM, 0);
                    $limit = $date->copy()->setTime((int) $toH, (int) $toM, 0);

                    while ($cursor->copy()->addMinutes($slotDuration)->lte($limit)) {
                        $start = $cursor->copy()->utc();
                        $end = $start->copy()->addMinutes($slotDuration);

                        if ($start->isFuture()) {
                            $slot = VisitSlot::firstOrCreate(
                                [
                                    'listing_id' => $listing->id,
                                    'start_at' => $start,
                                    'end_at' => $end,
                                ],
                                [
                                    'owner_id' => $listing->owner_id,
                                    'agent_id' => $listing->agent_id,
                                    'capacity' => $defaultCapacity,
                                    'booked_count' => 0,
                                    'status' => VisitSlotStatus::Open,
                                ]
                            );

                            $createdSlots[] = $slot;
                        }

                        $cursor->addMinutes($slotDuration + 15);
                    }
                }
            }

            return [
                'listing_id' => $listing->id,
                'total_slots' => count($createdSlots),
                'slots' => collect($createdSlots)->map(fn (VisitSlot $s) => [
                    'id' => $s->id,
                    'start_at' => $s->start_at->toIso8601String(),
                    'end_at' => $s->end_at->toIso8601String(),
                    'capacity' => $s->capacity,
                    'status' => $s->status->value,
                ])->values()->all(),
            ];
        });
    }
}
