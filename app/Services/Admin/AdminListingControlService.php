<?php

namespace App\Services\Admin;

use App\Enums\Boost\CampaignStatus;
use App\Enums\Listing\ListingStatus;
use App\Enums\Visit\VisitReservationStatus;
use App\Events\Listing\ListingPublished;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingAgentCertification;
use App\Models\Listing\ListingDuplicateDismissal;
use App\Models\Listing\ListingMerge;
use App\Models\Listing\ListingReviewLog;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use DomainException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AdminListingControlService
{
    /**
     * Liste paginée des annonces pour le back-office avec filtres et facettes.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getListings(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = Listing::with(['owner', 'agent', 'page', 'photos', 'campaigns']);

        // 1. Statut de publication
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 2. Mode de transaction (rent, sale, flatshare)
        if (! empty($filters['mode'])) {
            $query->where('mode', $filters['mode']);
        }

        // 3. Type de bien (studio, flat, villa, etc.)
        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        // 4. Ville et quartier
        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }
        if (! empty($filters['neighbourhood'])) {
            $query->whereRaw('LOWER(neighbourhood) = ?', [strtolower(trim((string) $filters['neighbourhood']))]);
        }

        // 5. Visité par un agent
        if (isset($filters['agent_visited'])) {
            $query->where('agent_visited', (bool) $filters['agent_visited']);
        }

        // 6. Présence d'un badge professionnel chez le propriétaire
        if (isset($filters['verified_owner'])) {
            $query->whereHas('owner', function (Builder $oq) {
                $oq->where('is_verified', true)
                    ->whereIn('badge', ['landlord', 'agent', 'double']);
            });
        }

        // 7. Filtre de prix
        if (! empty($filters['min_price'])) {
            $query->where('price', '>=', (float) $filters['min_price']);
        }
        if (! empty($filters['max_price'])) {
            $query->where('price', '<=', (float) $filters['max_price']);
        }

        // 8. Recherche textuelle (id, référence, titre, localisation, nom du propriétaire)
        if (! empty($filters['q'])) {
            $search = strtolower(trim((string) $filters['q']));
            $query->where(function (Builder $q) use ($search) {
                $q->where('id', 'LIKE', "%{$search}%")
                    ->orWhereRaw('LOWER(title) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(city) LIKE ?', ["%{$search}%"])
                    ->orWhereRaw('LOWER(neighbourhood) LIKE ?', ["%{$search}%"])
                    ->orWhereHas('owner', function (Builder $oq) use ($search) {
                        $oq->whereRaw('LOWER(name) LIKE ?', ["%{$search}%"])
                            ->orWhere('email', 'LIKE', "%{$search}%")
                            ->orWhere('phone', 'LIKE', "%{$search}%");
                    });
            });
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage);
    }

    /**
     * Fiche détaillée complète d'une annonce immobilière.
     */
    public function getListingDetail(string $idOrRef): Listing
    {
        return Listing::with([
            'owner.role',
            'agent.role',
            'page',
            'media',
            'photos',
            'videos',
            'visitSlots',
            'visitReservations.prospect',
            'campaigns',
            'merges.operator',
            'agentCertifications.agent',
            'reviewLogs.operator',
        ])
            ->where('id', $idOrRef)
            ->firstOrFail();
    }

    /**
     * B.2. Détection des doublons potentiels (même quartier/ville, même type, loyer proche à 85%).
     *
     * @return array<string, mixed>
     */
    public function getDuplicates(int $perPage = 15): array
    {
        // Récupère les paires déjà écartées (faux positifs)
        $dismissedPairs = ListingDuplicateDismissal::all()
            ->map(fn ($d) => "{$d->listing_id}:{$d->dismissed_listing_id}")
            ->toArray();

        // Récupère les paires déjà fusionnées
        $mergedPairs = ListingMerge::all()
            ->map(fn ($m) => "{$m->primary_listing_id}:{$m->secondary_listing_id}")
            ->toArray();

        // Récupère les annonces candidates (publiées ou en review)
        $candidates = Listing::with(['owner', 'photos'])
            ->whereIn('status', [ListingStatus::Published->value, ListingStatus::Review->value, ListingStatus::Draft->value])
            ->whereNotNull('city')
            ->whereNotNull('price')
            ->where('price', '>', 0)
            ->get();

        $duplicates = [];

        for ($i = 0; $i < $candidates->count(); $i++) {
            $a = $candidates[$i];

            for ($j = $i + 1; $j < $candidates->count(); $j++) {
                $b = $candidates[$j];

                // Ne compare pas une annonce avec elle-même
                if ($a->id === $b->id) {
                    continue;
                }

                $pairKey1 = "{$a->id}:{$b->id}";
                $pairKey2 = "{$b->id}:{$a->id}";

                if (in_array($pairKey1, $dismissedPairs, true) || in_array($pairKey2, $dismissedPairs, true)) {
                    continue;
                }
                if (in_array($pairKey1, $mergedPairs, true) || in_array($pairKey2, $mergedPairs, true)) {
                    continue;
                }

                // 1. Critère géographique : même ville
                if (strtolower(trim($a->city ?? '')) !== strtolower(trim($b->city ?? ''))) {
                    continue;
                }

                // 2. Même type de bien (kind) et mode (rent/sale)
                if ($a->kind !== $b->kind || $a->mode !== $b->mode) {
                    continue;
                }

                // 3. Critère de prix : similarité >= 85%
                $minPrice = min((float) $a->price, (float) $b->price);
                $maxPrice = max((float) $a->price, (float) $b->price);
                $priceSimilarity = $maxPrice > 0 ? ($minPrice / $maxPrice) : 0;

                if ($priceSimilarity < 0.85) {
                    continue;
                }

                // 4. Critère de rapprochement physique ou local (quartier similaire ou même nombre de chambres)
                $sameNeighbourhood = ! empty($a->neighbourhood) && strtolower(trim($a->neighbourhood)) === strtolower(trim($b->neighbourhood ?? ''));
                $sameBedrooms = ($a->bedrooms > 0 && $a->bedrooms === $b->bedrooms);

                if (! $sameNeighbourhood && ! $sameBedrooms) {
                    continue;
                }

                // Score de similarité global pondéré
                $score = round(($priceSimilarity * 0.6) + ($sameNeighbourhood ? 0.25 : 0.1) + ($sameBedrooms ? 0.15 : 0.05), 2);

                $duplicates[] = [
                    'similarity_score' => min(1.0, $score),
                    'price_similarity' => round($priceSimilarity * 100, 1),
                    'primary' => [
                        'id' => $a->id,
                        'title' => $a->title,
                        'price' => (float) $a->price,
                        'city' => $a->city,
                        'neighbourhood' => $a->neighbourhood,
                        'kind' => $a->kind->value,
                        'status' => $a->status->value,
                        'owner' => [
                            'id' => $a->owner->id,
                            'name' => $a->owner->name,
                        ],
                        'photo' => $a->mainPhoto()?->file_path,
                        'created_at' => $a->created_at->toISOString(),
                    ],
                    'duplicate' => [
                        'id' => $b->id,
                        'title' => $b->title,
                        'price' => (float) $b->price,
                        'city' => $b->city,
                        'neighbourhood' => $b->neighbourhood,
                        'kind' => $b->kind->value,
                        'status' => $b->status->value,
                        'owner' => [
                            'id' => $b->owner->id,
                            'name' => $b->owner->name,
                        ],
                        'photo' => $b->mainPhoto()?->file_path,
                        'created_at' => $b->created_at->toISOString(),
                    ],
                ];
            }
        }

        // Tri par score de similarité décroissant
        usort($duplicates, fn ($x, $y) => $y['similarity_score'] <=> $x['similarity_score']);

        return [
            'total' => count($duplicates),
            'data' => array_slice($duplicates, 0, $perPage),
        ];
    }

    /**
     * B.3. Fusion transactionnelle de deux doublons.
     */
    public function mergeDuplicates(string $primaryId, string $secondaryId, User $operator, ?string $reason = null): Listing
    {
        if ($primaryId === $secondaryId) {
            throw ValidationException::withMessages([
                'secondary_ref' => ['L\'annonce secondaire ne peut pas être identique à l\'annonce principale.'],
            ]);
        }

        // Empêcher une fusion répétée ou circulaire
        $alreadyMerged = ListingMerge::where(function ($q) use ($primaryId, $secondaryId) {
            $q->where('primary_listing_id', $primaryId)->where('secondary_listing_id', $secondaryId);
        })->orWhere(function ($q) use ($primaryId, $secondaryId) {
            $q->where('primary_listing_id', $secondaryId)->where('secondary_listing_id', $primaryId);
        })->orWhere('secondary_listing_id', $secondaryId)
            ->exists();

        if ($alreadyMerged) {
            throw new DomainException('Cette annonce a déjà été fusionnée ou fait l\'objet d\'une fusion circulaire.');
        }

        $primary = Listing::findOrFail($primaryId);
        $secondary = Listing::findOrFail($secondaryId);

        return DB::transaction(function () use ($primary, $secondary, $operator, $reason) {
            // 1. Dépublication de l'annonce secondaire
            $secondary->update([
                'status' => ListingStatus::Draft,
                'rejection_reason' => "Fusionnée avec l'annonce principale ref: {$primary->id}",
            ]);

            // 2. Préservation des visites réservées : rattachement à l'annonce principale
            VisitReservation::where('listing_id', $secondary->id)
                ->whereIn('status', [VisitReservationStatus::Requested->value, VisitReservationStatus::Confirmed->value])
                ->update(['listing_id' => $primary->id]);

            // 3. Enregistrement dans la table d'audit des fusions
            ListingMerge::create([
                'primary_listing_id' => $primary->id,
                'secondary_listing_id' => $secondary->id,
                'operator_id' => $operator->id,
                'reason' => $reason ?? 'Doublon avéré fusionné par l\'opérateur',
                'metadata' => [
                    'secondary_owner_id' => $secondary->owner_id,
                    'secondary_price' => (float) $secondary->price,
                ],
                'created_at' => now(),
            ]);

            // 4. Journalisation de l'audit
            ListingReviewLog::create([
                'listing_id' => $primary->id,
                'operator_id' => $operator->id,
                'action' => 'merge',
                'from_status' => $secondary->status->value,
                'to_status' => $primary->status->value,
                'reason' => "Fusion avec annonce {$secondary->id}: {$reason}",
                'created_at' => now(),
            ]);

            return $primary->fresh(['merges', 'reviewLogs']);
        });
    }

    /**
     * B.3 bis. Rejet d'une détection de doublon (faux positif).
     */
    public function dismissDuplicate(string $ref1, string $ref2, User $operator, ?string $reason = null): void
    {
        if ($ref1 === $ref2) {
            throw new DomainException('Impossible de rejeter un doublon pour une même annonce.');
        }

        $l1 = Listing::findOrFail($ref1);
        $l2 = Listing::findOrFail($ref2);

        // Sauvegarde de l'écartement de faux positif (paire ordonnée pour éviter les duplicatas)
        ListingDuplicateDismissal::updateOrCreate(
            [
                'listing_id' => min($l1->id, $l2->id),
                'dismissed_listing_id' => max($l1->id, $l2->id),
            ],
            [
                'operator_id' => $operator->id,
                'reason' => $reason ?? 'Déclaré distinct / non-doublon par l\'opérateur',
                'created_at' => now(),
            ]
        );
    }

    /**
     * B.4. Contrôle de publication (approve, reject, request_changes).
     */
    public function review(Listing $listing, User $operator, string $decision, ?string $reason = null): Listing
    {
        $oldStatus = $listing->status->value;

        return DB::transaction(function () use ($listing, $operator, $decision, $reason, $oldStatus) {
            $now = now();

            if ($decision === 'approve') {
                $listing->update([
                    'status' => ListingStatus::Published,
                    'published_at' => $now,
                    'rejection_reason' => null,
                ]);

                try {
                    event(new ListingPublished($listing));
                } catch (\Throwable $e) {
                    Log::warning("Event ListingPublished warning: {$e->getMessage()}");
                }

            } elseif ($decision === 'reject') {
                if (empty(trim((string) $reason))) {
                    throw ValidationException::withMessages([
                        'reason' => ['Un motif explicite est requis pour refuser la publication d\'une annonce.'],
                    ]);
                }

                $listing->update([
                    'status' => ListingStatus::Rejected,
                    'rejection_reason' => $reason,
                ]);

            } elseif (in_array($decision, ['request_changes', 'draft'], true)) {
                $listing->update([
                    'status' => ListingStatus::Draft,
                    'rejection_reason' => $reason ?? 'Corrections requises avant republication',
                ]);

            } else {
                throw new DomainException("Décision de revue '{$decision}' non valide.");
            }

            // Journalisation
            ListingReviewLog::create([
                'listing_id' => $listing->id,
                'operator_id' => $operator->id,
                'action' => $decision,
                'from_status' => $oldStatus,
                'to_status' => $listing->status->value,
                'reason' => $reason,
                'created_at' => $now,
            ]);

            return $listing->fresh();
        });
    }

    /**
     * B.4 bis. Traitement groupé d'annonces.
     *
     * @param  array<string>  $refs
     * @return array<int, array{ref: string, status: string, message: string}>
     */
    public function bulkReview(array $refs, User $operator, string $decision, ?string $reason = null): array
    {
        $uniqueRefs = array_unique($refs);
        $results = [];

        foreach ($uniqueRefs as $ref) {
            try {
                $listing = Listing::find($ref);
                if (! $listing) {
                    $results[] = [
                        'ref' => $ref,
                        'status' => 'failed',
                        'message' => 'Annonce introuvable.',
                    ];

                    continue;
                }

                $this->review($listing, $operator, $decision, $reason);

                $results[] = [
                    'ref' => $ref,
                    'status' => 'success',
                    'message' => "Décision '{$decision}' appliquée avec succès.",
                ];
            } catch (\Throwable $e) {
                $results[] = [
                    'ref' => $ref,
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /**
     * B.5. Certification de visite sur place par un agent ou vérificateur.
     *
     * @param  array<string, mixed>  $data
     */
    public function certifyAgentVisit(Listing $listing, User $operator, array $data = []): ListingAgentCertification
    {
        return DB::transaction(function () use ($listing, $operator, $data) {
            $now = now();

            $listing->update([
                'agent_visited' => true,
            ]);

            $certification = ListingAgentCertification::create([
                'listing_id' => $listing->id,
                'agent_id' => $operator->id,
                'certified_at' => $now,
                'notes' => $data['notes'] ?? 'Visite physique validée sur place par l\'agent',
                'evidence' => $data['evidence'] ?? null,
            ]);

            ListingReviewLog::create([
                'listing_id' => $listing->id,
                'operator_id' => $operator->id,
                'action' => 'agent_visit_certified',
                'from_status' => $listing->status->value,
                'to_status' => $listing->status->value,
                'reason' => 'Certification de visite sur place enregistrée',
                'created_at' => $now,
            ]);

            return $certification;
        });
    }

    /**
     * B.6. Dépublication / suspension d'une annonce.
     */
    public function unpublish(Listing $listing, User $operator, ?string $reason = null): Listing
    {
        $oldStatus = $listing->status->value;

        return DB::transaction(function () use ($listing, $operator, $reason, $oldStatus) {
            $now = now();

            $listing->update([
                'status' => ListingStatus::Draft,
                'rejection_reason' => $reason ?? 'Dépubliée par l\'administration',
            ]);

            // Met en pause les campagnes Boost actives sur cette annonce
            $listing->campaigns()
                ->where('status', CampaignStatus::Active->value)
                ->update(['status' => CampaignStatus::Paused->value]);

            ListingReviewLog::create([
                'listing_id' => $listing->id,
                'operator_id' => $operator->id,
                'action' => 'unpublish',
                'from_status' => $oldStatus,
                'to_status' => ListingStatus::Draft->value,
                'reason' => $reason,
                'created_at' => $now,
            ]);

            return $listing->fresh();
        });
    }

    /**
     * B.6 bis. Republication d'une annonce.
     */
    public function republish(Listing $listing, User $operator, ?string $reason = null): Listing
    {
        $oldStatus = $listing->status->value;

        return DB::transaction(function () use ($listing, $operator, $reason, $oldStatus) {
            $now = now();

            $listing->update([
                'status' => ListingStatus::Published,
                'published_at' => $now,
                'rejection_reason' => null,
            ]);

            // Réactive les campagnes Boost si elles étaient en pause
            $listing->campaigns()
                ->where('status', CampaignStatus::Paused->value)
                ->update(['status' => CampaignStatus::Active->value]);

            try {
                event(new ListingPublished($listing));
            } catch (\Throwable $e) {
                Log::warning("Event ListingPublished warning: {$e->getMessage()}");
            }

            ListingReviewLog::create([
                'listing_id' => $listing->id,
                'operator_id' => $operator->id,
                'action' => 'republish',
                'from_status' => $oldStatus,
                'to_status' => ListingStatus::Published->value,
                'reason' => $reason ?? 'Republication validée par l\'administration',
                'created_at' => $now,
            ]);

            return $listing->fresh();
        });
    }

    /**
     * B.7. Marquage d'un bien comme loué / occupé (taken).
     * Interrompt immédiatement les campagnes de boost sans remboursement indu et annule les visites futures.
     */
    public function markAsRented(Listing $listing, User $operator, ?string $reason = null): Listing
    {
        $oldStatus = $listing->status->value;

        return DB::transaction(function () use ($listing, $operator, $reason, $oldStatus) {
            $now = now();

            // 1. Passage au statut Loué / Occupé (taken)
            $listing->update([
                'status' => ListingStatus::Taken,
                'taken_at' => $now,
            ]);

            // 2. Arrêt immédiat des campagnes Boost actives (statut completed / paused, pas de remboursement automatique)
            $listing->campaigns()
                ->where('status', CampaignStatus::Active->value)
                ->update(['status' => CampaignStatus::Completed->value]);

            // 3. Clôture des visites en attente
            VisitReservation::where('listing_id', $listing->id)
                ->where('status', VisitReservationStatus::Requested->value)
                ->update([
                    'status' => VisitReservationStatus::Cancelled->value,
                    'cancellation_reason' => 'Le bien a été loué / retiré du marché',
                    'cancelled_at' => $now,
                ]);

            // 4. Journalisation de l'audit
            ListingReviewLog::create([
                'listing_id' => $listing->id,
                'operator_id' => $operator->id,
                'action' => 'rented',
                'from_status' => $oldStatus,
                'to_status' => ListingStatus::Taken->value,
                'reason' => $reason ?? 'Marqué comme loué par l\'administration',
                'created_at' => $now,
            ]);

            return $listing->fresh();
        });
    }

    /**
     * B.8. Données géographiques pour la vue cartographique administrative.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function getMapListings(array $filters = []): Collection
    {
        $query = Listing::whereNotNull('lat')
            ->whereNotNull('lng')
            ->where('lat', '!=', 0)
            ->where('lng', '!=', 0);

        if (! empty($filters['city'])) {
            $query->whereRaw('LOWER(city) = ?', [strtolower(trim((string) $filters['city']))]);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        return $query->limit(200)->get()->map(function (Listing $l) {
            return [
                'id' => $l->id,
                'title' => $l->title,
                'lat' => (float) $l->lat,
                'lng' => (float) $l->lng,
                'price' => (float) $l->price,
                'currency' => $l->currency,
                'city' => $l->city,
                'neighbourhood' => $l->neighbourhood,
                'kind' => $l->kind->value,
                'status' => $l->status->value,
                'agent_visited' => (bool) $l->agent_visited,
            ];
        });
    }

    /**
     * B.9. Suivi global des visites réservées avec état du SLA de réponse (18 heures).
     *
     * @param  array<string, mixed>  $filters
     */
    public function getVisits(array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        $query = VisitReservation::with(['listing', 'slot', 'prospect', 'owner', 'agent', 'paymentTransaction']);

        // 1. Filtre par statut
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        // 2. Filtre par annonce
        if (! empty($filters['listing_id'])) {
            $query->where('listing_id', $filters['listing_id']);
        }

        // 3. Filtre dépassement SLA 18h (demandes en attente depuis plus de 18h)
        if (! empty($filters['overdue_sla'])) {
            $slaLimit = now()->subHours(18);
            $query->where('status', VisitReservationStatus::Requested->value)
                ->where('created_at', '<', $slaLimit);
        }

        // 4. Recherche textuelle
        if (! empty($filters['q'])) {
            $search = strtolower(trim((string) $filters['q']));
            $query->where(function (Builder $q) use ($search) {
                $q->whereHas('prospect', fn ($pq) => $pq->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%"))
                    ->orWhereHas('owner', fn ($oq) => $oq->where('name', 'LIKE', "%{$search}%")->orWhere('email', 'LIKE', "%{$search}%"))
                    ->orWhereHas('listing', fn ($lq) => $lq->where('title', 'LIKE', "%{$search}%"));
            });
        }

        $query->orderByDesc('created_at');

        return $query->paginate($perPage);
    }
}
