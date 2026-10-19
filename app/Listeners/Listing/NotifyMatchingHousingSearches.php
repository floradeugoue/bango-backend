<?php

namespace App\Listeners\Listing;

use App\Enums\HousingIntent;
use App\Enums\Listing\ListingStatus;
use App\Events\Listing\ListingPublished;
use App\Models\Housing\HousingSearch;
use App\Notifications\Listing\NewMatchingListingNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyMatchingHousingSearches implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Traite l'événement de publication d'une annonce immobilière.
     */
    public function handle(ListingPublished $event): void
    {
        $listing = $event->listing;

        // Une annonce non publiée, archivée ou supprimée ne doit déclencher aucune alerte
        if ($listing->status !== ListingStatus::Published || $listing->trashed()) {
            return;
        }

        $query = HousingSearch::query()
            ->with('user')
            ->where('user_id', '!=', $listing->owner_id)
            ->where(function ($q) {
                $q->whereNull('intent')
                    ->orWhere('intent', '!=', HousingIntent::No->value);
            });

        // Filtrage initial par ville en base si renseignée
        if ($listing->city) {
            $query->where(function ($q) use ($listing) {
                $q->whereNull('city')
                    ->orWhere('city', '')
                    ->orWhereRaw('LOWER(city) = ?', [mb_strtolower(trim($listing->city))]);
            });
        }

        $query->chunk(100, function ($searches) use ($listing) {
            foreach ($searches as $search) {
                $user = $search->user;
                if (! $user || $user->isSuspended()) {
                    continue;
                }

                if (! $this->matchesSearchCriteria($search, $listing)) {
                    continue;
                }

                // Déduplication : éviter les notifications répétées pour une même annonce
                $driver = DB::connection()->getDriverName();
                $alreadyNotified = $user->notifications()
                    ->when(
                        $driver === 'pgsql',
                        fn ($q) => $q->whereRaw("CAST(data AS jsonb)->>'listing_id' = ?", [(string) $listing->id]),
                        fn ($q) => $q->where('data->listing_id', (string) $listing->id)
                    )
                    ->exists();

                if ($alreadyNotified) {
                    continue;
                }

                try {
                    $user->notify(new NewMatchingListingNotification($listing));
                } catch (\Throwable $e) {
                    Log::warning('Échec notification alerte logement : '.$e->getMessage(), [
                        'user_id' => $user->id,
                        'listing_id' => $listing->id,
                    ]);
                }
            }
        });
    }

    /**
     * Vérifie si l'annonce correspond aux critères détaillés de la recherche enregistrée.
     */
    protected function matchesSearchCriteria(HousingSearch $search, $listing): bool
    {
        // 1. Quartier
        if (! empty($search->neighbourhoods) && is_array($search->neighbourhoods) && $listing->neighbourhood) {
            $normalizedListingNeighbourhood = mb_strtolower(trim($listing->neighbourhood));
            $searchNeighbourhoods = array_map(fn ($n) => mb_strtolower(trim((string) $n)), $search->neighbourhoods);

            if (! in_array($normalizedListingNeighbourhood, $searchNeighbourhoods, true)) {
                return false;
            }
        }

        // 2. Budget [min, max]
        if (! empty($search->budget) && is_array($search->budget)) {
            $minBudget = isset($search->budget[0]) && is_numeric($search->budget[0]) ? (float) $search->budget[0] : null;
            $maxBudget = isset($search->budget[1]) && is_numeric($search->budget[1]) ? (float) $search->budget[1] : null;
            $listingPrice = (float) $listing->price;

            if ($minBudget !== null && $minBudget > 0 && $listingPrice < $minBudget) {
                return false;
            }

            if ($maxBudget !== null && $maxBudget > 0 && $listingPrice > $maxBudget) {
                return false;
            }
        }

        // 3. Types de biens
        if (! empty($search->types) && is_array($search->types)) {
            $listingKind = $listing->kind?->value ?? (string) $listing->kind;
            $searchTypes = array_map(fn ($t) => mb_strtolower(trim((string) $t)), $search->types);

            if (! in_array(mb_strtolower($listingKind), $searchTypes, true)) {
                return false;
            }
        }

        return true;
    }
}
