<?php

namespace App\Listeners\Listing;

use App\Enums\HousingIntent;
use App\Enums\Listing\ListingStatus;
use App\Events\Listing\ListingPublished;
use App\Models\Housing\HousingSearch;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingAlert;
use App\Notifications\Listing\NewMatchingListingNotification;
use App\Services\Notification\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NotifyMatchingHousingSearches implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Traite l'événement de publication ou remise en ligne d'une annonce immobilière.
     */
    public function handle(ListingPublished $event): void
    {
        /** @var Listing $listing */
        $listing = $event->listing;

        // Une annonce non publiée, archivée ou supprimée ne doit déclencher aucune alerte
        if ($listing->status !== ListingStatus::Published || $listing->trashed()) {
            return;
        }

        // 1. Traitement des recherches de profil (HousingSearch)
        $this->processHousingSearches($listing);

        // 2. Traitement des alertes de recherche dédiées (ListingAlert)
        $this->processListingAlerts($listing);
    }

    /**
     * Traiter les alertes issues de HousingSearch.
     */
    protected function processHousingSearches(Listing $listing): void
    {
        $query = HousingSearch::query()
            ->with('user')
            ->where('user_id', '!=', $listing->owner_id)
            ->where(function ($q) {
                $q->whereNull('intent')
                    ->orWhere('intent', '!=', HousingIntent::No->value);
            });

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

                if ($this->hasAlreadyBeenNotified($user, $listing)) {
                    continue;
                }

                try {
                    $user->notify(new NewMatchingListingNotification($listing));
                    $this->sendPushNotification($user, $listing);
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
     * Traiter les alertes personnalisées de recherche (ListingAlert).
     */
    protected function processListingAlerts(Listing $listing): void
    {
        $query = ListingAlert::query()
            ->with('user')
            ->where('user_id', '!=', $listing->owner_id)
            ->where('frequency', 'instant');

        if ($listing->city) {
            $query->where(function ($q) use ($listing) {
                $q->whereNull('city')
                    ->orWhere('city', '')
                    ->orWhereRaw('LOWER(city) = ?', [mb_strtolower(trim($listing->city))]);
            });
        }

        $query->chunk(100, function ($alerts) use ($listing) {
            foreach ($alerts as $alert) {
                $user = $alert->user;
                if (! $user || $user->isSuspended()) {
                    continue;
                }

                if (! $this->matchesAlertCriteria($alert, $listing)) {
                    continue;
                }

                if ($this->hasAlreadyBeenNotified($user, $listing)) {
                    continue;
                }

                try {
                    $user->notify(new NewMatchingListingNotification($listing));

                    if ($alert->push) {
                        $this->sendPushNotification($user, $listing);
                    }

                    $alert->update(['last_triggered_at' => now()]);
                } catch (\Throwable $e) {
                    Log::warning('Échec notification alerte recherche : '.$e->getMessage(), [
                        'user_id' => $user->id,
                        'listing_id' => $listing->id,
                        'alert_id' => $alert->id,
                    ]);
                }
            }
        });
    }

    /**
     * Vérifie si l'utilisateur a déjà reçu une notification pour cette annonce.
     */
    protected function hasAlreadyBeenNotified($user, Listing $listing): bool
    {
        $driver = DB::connection()->getDriverName();

        return $user->notifications()
            ->when(
                $driver === 'pgsql',
                fn ($q) => $q->whereRaw("CAST(data AS jsonb)->>'listing_id' = ?", [(string) $listing->id]),
                fn ($q) => $q->where('data->listing_id', (string) $listing->id)
            )
            ->exists();
    }

    /**
     * Expédie la notification push FCM/APNs.
     */
    protected function sendPushNotification($user, Listing $listing): void
    {
        try {
            $pushService = app(PushNotificationService::class);
            $title = 'Nouvelle annonce disponible';
            $priceFormatted = number_format((float) $listing->price, 0, ',', ' ').' '.($listing->currency ?? 'XAF');
            $location = trim(($listing->neighbourhood ? $listing->neighbourhood.', ' : '').$listing->city);
            $body = "{$listing->title} ({$priceFormatted} · {$location})";

            $pushService->sendToUser($user, $title, $body, [
                'type' => 'new_matching_listing',
                'listing_id' => (string) $listing->id,
            ]);
        } catch (\Throwable $e) {
            Log::info('Erreur envoi push alerte recherche : '.$e->getMessage());
        }
    }

    /**
     * Vérifie si l'annonce correspond aux critères détaillés de HousingSearch.
     */
    protected function matchesSearchCriteria(HousingSearch $search, Listing $listing): bool
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

    /**
     * Vérifie si l'annonce correspond aux critères d'une ListingAlert.
     */
    protected function matchesAlertCriteria(ListingAlert $alert, Listing $listing): bool
    {
        // 1. Mode (rent, sale, flatshare)
        if ($alert->mode) {
            $listingMode = $listing->mode?->value ?? (string) $listing->mode;
            if (mb_strtolower(trim($alert->mode)) !== mb_strtolower(trim($listingMode))) {
                return false;
            }
        }

        // 2. Ville
        if ($alert->city && $listing->city) {
            if (mb_strtolower(trim($alert->city)) !== mb_strtolower(trim($listing->city))) {
                return false;
            }
        }

        // 3. Quartiers
        if (! empty($alert->neighbourhoods) && is_array($alert->neighbourhoods)) {
            if (! $listing->neighbourhood) {
                return false;
            }

            $normalizedListingNeighbourhood = mb_strtolower(trim($listing->neighbourhood));
            $alertNeighbourhoods = array_map(fn ($n) => mb_strtolower(trim((string) $n)), $alert->neighbourhoods);

            if (! in_array($normalizedListingNeighbourhood, $alertNeighbourhoods, true)) {
                return false;
            }
        }

        // 4. Types / Kinds
        if (! empty($alert->kinds) && is_array($alert->kinds)) {
            $listingKind = $listing->kind?->value ?? (string) $listing->kind;
            $alertKinds = array_map(fn ($k) => mb_strtolower(trim((string) $k)), $alert->kinds);

            if (! in_array(mb_strtolower($listingKind), $alertKinds, true)) {
                return false;
            }
        }

        // 5. Budget min / max
        $listingPrice = (float) $listing->price;
        if ($alert->budget_min !== null && (float) $alert->budget_min > 0) {
            if ($listingPrice < (float) $alert->budget_min) {
                return false;
            }
        }

        if ($alert->budget_max !== null && (float) $alert->budget_max > 0) {
            if ($listingPrice > (float) $alert->budget_max) {
                return false;
            }
        }

        // 6. Chambres (bedrooms)
        if (! empty($alert->bedrooms) && is_array($alert->bedrooms)) {
            $listingBedrooms = (int) ($listing->bedrooms ?? 0);
            $targetBedrooms = array_map('intval', $alert->bedrooms);

            if (! in_array($listingBedrooms, $targetBedrooms, true)) {
                return false;
            }
        }

        // 7. Douches (showers)
        if (! empty($alert->showers) && is_array($alert->showers)) {
            $listingShowers = (int) ($listing->showers ?? 0);
            $targetShowers = array_map('intval', $alert->showers);

            if (! in_array($listingShowers, $targetShowers, true)) {
                return false;
            }
        }

        // 8. Superficie (area_min / area_max)
        $listingArea = $listing->area !== null ? (float) $listing->area : null;
        if ($alert->area_min !== null && (float) $alert->area_min > 0) {
            if ($listingArea === null || $listingArea < (float) $alert->area_min) {
                return false;
            }
        }

        if ($alert->area_max !== null && (float) $alert->area_max > 0) {
            if ($listingArea === null || $listingArea > (float) $alert->area_max) {
                return false;
            }
        }

        // 9. Équipements / Amenities
        if (! empty($alert->amenities) && is_array($alert->amenities)) {
            $listingAmenities = is_array($listing->amenities) ? $listing->amenities : [];
            $normalizedListingAmenities = array_map(fn ($a) => mb_strtolower(trim((string) $a)), $listingAmenities);

            foreach ($alert->amenities as $requiredAmenity) {
                if (! in_array(mb_strtolower(trim((string) $requiredAmenity)), $normalizedListingAmenities, true)) {
                    return false;
                }
            }
        }

        // 10. Filtres booléens
        if ($alert->verified_only) {
            $owner = $listing->owner;
            if (! $owner || ! $owner->is_verified) {
                return false;
            }
        }

        if ($alert->agent_visited_only && ! $listing->agent_visited) {
            return false;
        }

        if ($alert->utilities_included_only && ! $listing->utilities_included) {
            return false;
        }

        return true;
    }
}
