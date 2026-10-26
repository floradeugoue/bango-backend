<?php

namespace App\Services\Boost;

use App\Enums\Boost\CampaignObjective;
use App\Models\Boost\Campaign;
use App\Models\Boost\CampaignImpression;
use App\Models\Boost\CampaignResult;
use App\Models\Geo\City;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingAlert;
use App\Models\Listing\ListingLike;
use App\Models\Listing\ListingSave;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Page\Page;
use App\Models\Profile\Follow;
use App\Models\Profile\FollowRequest;
use App\Models\Reel\Reel;
use App\Models\Reel\ReelComment;
use App\Models\Reel\ReelReaction;
use App\Models\Reel\ReelView;
use App\Models\Square\Post;
use App\Models\Square\PostComment;
use App\Models\Square\PostLike;
use App\Models\Square\PostShare;
use App\Models\Square\PostView;
use App\Models\User;
use App\Models\Visit\ListingVisitRequest;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class AdDeliveryService
{
    /**
     * Cache duration for frequency capping (48 hours).
     */
    protected const FREQUENCY_CAPPING_HOURS = 48;

    /**
     * Max daily impressions per user per campaign.
     */
    protected const MAX_DAILY_IMPRESSIONS = 3;

    /**
     * Récupère les campagnes actives éligibles à l'injection publicitaire dans un feed pour un utilisateur donné.
     *
     * @return Collection<int, Campaign>
     */
    public function getEligibleCampaigns(string $boostableClass, ?User $user = null, array $context = []): Collection
    {
        // 1. Recherche des campagnes actives respectant dates et budgets
        $campaigns = Campaign::query()
            ->active()
            ->where('boostable_type', $boostableClass)
            ->with(['boostable', 'user', 'page'])
            ->get();

        // Consentement LG : vérifier si l'utilisateur autorise le profilage personnalisé
        $allowsPersonalization = true;
        if ($user) {
            $privacySettings = $user->privacy_settings ?? [];
            $allowsPersonalization = (bool) ($privacySettings['ads_personalization'] ?? true);
        }

        $userCity = $user?->city ?? ($context['city'] ?? null);

        // Récupérer les centres d'intérêt du spectateur
        $viewerInterestIds = $user ? $user->interests()->pluck('interests.id')->all() : [];

        // 2. Étape 1 : Filtrage strict (qui peut voir cette campagne)
        return $campaigns->filter(function (Campaign $campaign) use ($user, $userCity, $allowsPersonalization, $viewerInterestIds) {
            return $this->passesTargetingFilters($campaign, $user, $userCity, $allowsPersonalization, $viewerInterestIds);
        })->values();
    }

    /**
     * Étape 1 : Filtre d'éligibilité strict.
     */
    public function passesTargetingFilters(
        Campaign $campaign,
        ?User $user,
        ?string $userCity,
        bool $allowsPersonalization,
        array $viewerInterestIds
    ): bool {
        // A. Vérification de l'intégrité de la campagne
        if (! $campaign->canBeServed()) {
            return false;
        }

        // B. Vérification du commanditaire
        if ($campaign->page_id) {
            $page = $campaign->page;
            if (! $page || ! $page->isActive() || ! $page->isCertified()) {
                return false;
            }
        } else {
            $creator = $campaign->user;
            if (! $creator || $creator->status === 'suspended') {
                return false;
            }
        }

        // C. Vérification de la disponibilité du contenu source
        $content = $campaign->boostable;
        if (! $content) {
            return false;
        }

        if ($content instanceof Post) {
            if ($content->is_archived) {
                return false;
            }
            if ($content->user && $content->user->status === 'suspended') {
                return false;
            }
            if ($content->page_id && ($content->page && ! $content->page->isActive())) {
                return false;
            }
        } elseif ($content instanceof Reel) {
            $reelStatus = is_object($content->status) ? $content->status->value : (string) $content->status;
            if ($reelStatus !== 'ready') {
                return false;
            }
            if ($content->user && $content->user->status === 'suspended') {
                return false;
            }
            if ($content->page_id && ($content->page && ! $content->page->isActive())) {
                return false;
            }
        } elseif ($content instanceof Listing) {
            $listingStatus = is_object($content->status) ? $content->status->value : (string) $content->status;
            if ($listingStatus === 'taken' || $listingStatus === 'archived') {
                return false;
            }
        }

        // Si aucun utilisateur authentifié n'est fourni, on applique uniquement les règles génériques
        if (! $user) {
            $targeting = $campaign->targeting ?? [];
            if (! empty($targeting['city'])) {
                if (! $userCity || strcasecmp($targeting['city'], $userCity) !== 0) {
                    return false;
                }
            }

            return empty($targeting['neighbourhoods']) && empty($targeting['interests']) && empty($targeting['age_min']) && empty($targeting['age_max']);
        }

        // D. Exclusions de base :
        // 1. Le commanditaire lui-même
        if ((int) $campaign->user_id === (int) $user->id) {
            return false;
        }
        if ($campaign->page_id && $campaign->page && $campaign->page->isOwner($user)) {
            return false;
        }

        // 2. Blocages réciproques ou masquages (user_blocks, user_mutes)
        $sponsorUserId = $campaign->user_id ?: $campaign->page?->owner_id;
        if ($sponsorUserId) {
            if ($user->hasBlocked($sponsorUserId) || $user->isBlockedBy($sponsorUserId)) {
                return false;
            }
            if (in_array((int) $sponsorUserId, $user->activeMutedUserIds(), true)) {
                return false;
            }
            $sponsorUser = $campaign->user ?: $campaign->page?->owner;
            if ($sponsorUser && in_array((int) $user->id, $sponsorUser->activeMutedUserIds(), true)) {
                return false;
            }
        }

        // 3. Contenu marqué en "pas intéressé"
        if ($content instanceof Reel && method_exists($content, 'notInterestedByUsers')) {
            if ($content->notInterestedByUsers()->where('user_id', $user->id)->exists()) {
                return false;
            }
        }

        // 4. Plafond de répétition : max 3 affichages par personne par campagne par jour
        $today = now()->format('Y-m-d');
        $frequencyKey = "ad_freq:{$campaign->id}:{$user->id}:{$today}";
        if ((int) Cache::get($frequencyKey, 0) >= self::MAX_DAILY_IMPRESSIONS) {
            return false;
        }

        // E. Ciblage géographique et démographique
        $targeting = $campaign->targeting ?? [];

        // Règle ads_personalization : si false, exclure les campagnes avec profilage personnalisé
        $hasProfiling = ! empty($targeting['age_min'])
            || ! empty($targeting['age_max'])
            || ! empty($targeting['interests'])
            || ! empty($targeting['neighbourhoods']);

        if (! $allowsPersonalization && $hasProfiling) {
            return false;
        }

        // Ville et rayon géographique
        if (! empty($targeting['city'])) {
            if (! $userCity) {
                return false;
            }

            $radiusKm = ! empty($targeting['radius_km']) ? (float) $targeting['radius_km'] : null;
            if ($radiusKm !== null && $radiusKm > 0) {
                if (strcasecmp($targeting['city'], $userCity) !== 0) {
                    $distance = $this->calculateCityDistance($targeting['city'], $userCity);
                    if ($distance === null || $distance > $radiusKm) {
                        return false;
                    }
                }
            } else {
                if (strcasecmp($targeting['city'], $userCity) !== 0) {
                    return false;
                }
            }
        }

        // Quartiers : si targeting.neighbourhoods n'est pas vide, users.neighbourhood doit en faire partie
        if (! empty($targeting['neighbourhoods']) && is_array($targeting['neighbourhoods'])) {
            if (empty($user->neighbourhood)) {
                return false;
            }
            $matched = false;
            foreach ($targeting['neighbourhoods'] as $nb) {
                if (strcasecmp((string) $nb, (string) $user->neighbourhood) === 0) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                return false;
            }
        }

        // Âge : calculé depuis users.birth_date, entre age_min et age_max inclus
        if (! empty($targeting['age_min']) || ! empty($targeting['age_max'])) {
            if (! $user->birth_date) {
                return false;
            }
            $userAge = Carbon::parse($user->birth_date)->age;
            if (! empty($targeting['age_min']) && $userAge < (int) $targeting['age_min']) {
                return false;
            }
            if (! empty($targeting['age_max']) && $userAge > (int) $targeting['age_max']) {
                return false;
            }
        }

        // Centres d'intérêt : au moins un en commun entre interest_user.interest_id et targeting.interests
        if (! empty($targeting['interests']) && is_array($targeting['interests'])) {
            $commonInterests = array_intersect($targeting['interests'], $viewerInterestIds);
            if (empty($commonInterests)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calcule la distance Haversine en kilomètres entre deux villes.
     */
    public function calculateCityDistance(string $city1Name, string $city2Name): ?float
    {
        $city1 = City::query()->where('name', $city1Name)->first();
        $city2 = City::query()->where('name', $city2Name)->first();

        if (! $city1 || ! $city2 || $city1->lat === null || $city1->lng === null || $city2->lat === null || $city2->lng === null) {
            return null;
        }

        return $this->haversineGreatCircleDistance($city1->lat, $city1->lng, $city2->lat, $city2->lng);
    }

    /**
     * Formule de Haversine en kilomètres.
     */
    protected function haversineGreatCircleDistance(float $latitudeFrom, float $longitudeFrom, float $latitudeTo, float $longitudeTo): float
    {
        $earthRadius = 6371.0;

        $latFrom = deg2rad($latitudeFrom);
        $lonFrom = deg2rad($longitudeFrom);
        $latTo = deg2rad($latitudeTo);
        $lonTo = deg2rad($longitudeTo);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $angle = 2 * asin(sqrt(pow(sin($latDelta / 2), 2) +
            cos($latFrom) * cos($latTo) * pow(sin($lonDelta / 2), 2)));

        return $angle * $earthRadius;
    }

    /**
     * Étape 2 : Score déterministe qui évalue les chances d'atteindre l'objectif de la campagne.
     * Renvoie 0.0 si la campagne ou le profil doit être exclu ou n'a aucun signal positif.
     */
    public function computeObjectiveScore(Campaign $campaign, User $user): float
    {
        $sponsorUserId = $campaign->user_id;
        $page = $campaign->page;
        $content = $campaign->boostable;

        $objectiveValue = $campaign->objective instanceof CampaignObjective
            ? $campaign->objective->value
            : (string) $campaign->objective;

        // Centres d'intérêt de l'utilisateur
        $userInterestIds = $user->interests()->pluck('interests.id')->all();
        $targetingInterests = $campaign->targeting['interests'] ?? [];

        $baseScore = match ($objectiveValue) {
            'profile_visits', 'followers' => $this->scoreForFollowersObjective($campaign, $user, $sponsorUserId, $page, $userInterestIds, $targetingInterests),
            'messages' => $this->scoreForMessagesObjective($campaign, $user, $sponsorUserId, $page, $content, $userInterestIds, $targetingInterests),
            'reach' => $this->scoreForReachObjective($campaign, $user, $content, $userInterestIds, $targetingInterests),
            'engagement' => $this->scoreForEngagementObjective($campaign, $user, $sponsorUserId, $page, $content, $userInterestIds, $targetingInterests),
            default => 1.0 + (count(array_intersect($userInterestIds, $targetingInterests)) * 2),
        };

        if ($baseScore <= 0.0) {
            return 0.0;
        }

        // Apprentissage : à partir de ~200 impressions, comparer le taux de résultat par tranche
        // (centre d'intérêt, tranche d'âge, quartier) et augmenter le poids des tranches qui convertissent le mieux
        $learnedBoost = $this->computeLearnedSliceBoost($campaign, $user, $userInterestIds);

        return $baseScore + $learnedBoost;
    }

    /**
     * Calcule le bonus d'apprentissage adaptatif basé sur les taux de conversion des tranches.
     */
    public function computeLearnedSliceBoost(Campaign $campaign, User $user, array $userInterestIds): float
    {
        // Seuil d'apprentissage : au moins 200 impressions enregistrées
        if ($campaign->impressions_count < 200) {
            return 0.0;
        }

        // Récupérer les métadonnées de performances apprises en cache ou les calculer
        $cacheKey = "campaign_learning_slices:{$campaign->id}";
        $sliceStats = Cache::remember($cacheKey, now()->addMinutes(30), function () use ($campaign) {
            return $this->analyzeCampaignSlicePerformance($campaign);
        });

        if (empty($sliceStats) || empty($sliceStats['avg_rate'])) {
            return 0.0;
        }

        $avgRate = $sliceStats['avg_rate'];
        $boost = 0.0;

        // 1. Tranche d'âge du spectateur
        if ($user->birth_date) {
            $age = Carbon::parse($user->birth_date)->age;
            $ageSlice = match (true) {
                $age < 25 => '18-24',
                $age <= 34 => '25-34',
                $age <= 44 => '35-44',
                default => '45+',
            };

            $ageRate = $sliceStats['ages'][$ageSlice] ?? 0.0;
            if ($ageRate > $avgRate && $avgRate > 0) {
                // Surperformance relative : bonus proportionnel jusqu'à +3
                $boost += min(3.0, (($ageRate - $avgRate) / $avgRate) * 2.0);
            }
        }

        // 2. Quartier du spectateur
        if ($user->neighbourhood) {
            $neighbourhoodRate = $sliceStats['neighbourhoods'][strtolower($user->neighbourhood)] ?? 0.0;
            if ($neighbourhoodRate > $avgRate && $avgRate > 0) {
                $boost += min(3.0, (($neighbourhoodRate - $avgRate) / $avgRate) * 2.0);
            }
        }

        // 3. Centres d'intérêt
        foreach ($userInterestIds as $interestId) {
            $interestRate = $sliceStats['interests'][$interestId] ?? 0.0;
            if ($interestRate > $avgRate && $avgRate > 0) {
                $boost += min(2.0, (($interestRate - $avgRate) / $avgRate) * 1.5);
            }
        }

        return $boost;
    }

    /**
     * Analyse les taux de conversion réels par tranche pour une campagne donnée.
     *
     * @return array{avg_rate: float, ages: array<string, float>, neighbourhoods: array<string, float>, interests: array<string, float>}
     */
    protected function analyzeCampaignSlicePerformance(Campaign $campaign): array
    {
        $impressions = CampaignImpression::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('user_id')
            ->with(['user.interests'])
            ->get();

        if ($impressions->isEmpty()) {
            return ['avg_rate' => 0.0, 'ages' => [], 'neighbourhoods' => [], 'interests' => []];
        }

        // Récupérer les IDs des impressions ayant converti (généré un résultat)
        $convertingImpressionIds = CampaignResult::query()
            ->where('campaign_id', $campaign->id)
            ->whereNotNull('impression_id')
            ->pluck('impression_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $convertedMap = array_fill_keys($convertingImpressionIds, true);

        $totalImpressions = $impressions->count();
        $totalResults = count($convertingImpressionIds);
        $avgRate = $totalImpressions > 0 ? ($totalResults / $totalImpressions) : 0.0;

        $ageCounts = [];
        $ageSuccess = [];

        $nbCounts = [];
        $nbSuccess = [];

        $interestCounts = [];
        $interestSuccess = [];

        foreach ($impressions as $imp) {
            $viewer = $imp->user;
            if (! $viewer) {
                continue;
            }

            $converted = isset($convertedMap[(int) $imp->id]);

            // Tranche d'âge
            if ($viewer->birth_date) {
                $age = Carbon::parse($viewer->birth_date)->age;
                $slice = match (true) {
                    $age < 25 => '18-24',
                    $age <= 34 => '25-34',
                    $age <= 44 => '35-44',
                    default => '45+',
                };
                $ageCounts[$slice] = ($ageCounts[$slice] ?? 0) + 1;
                if ($converted) {
                    $ageSuccess[$slice] = ($ageSuccess[$slice] ?? 0) + 1;
                }
            }

            // Quartier
            if ($viewer->neighbourhood) {
                $nb = strtolower($viewer->neighbourhood);
                $nbCounts[$nb] = ($nbCounts[$nb] ?? 0) + 1;
                if ($converted) {
                    $nbSuccess[$nb] = ($nbSuccess[$nb] ?? 0) + 1;
                }
            }

            // Intérêts
            foreach ($viewer->interests as $interest) {
                $intId = $interest->id;
                $interestCounts[$intId] = ($interestCounts[$intId] ?? 0) + 1;
                if ($converted) {
                    $interestSuccess[$intId] = ($interestSuccess[$intId] ?? 0) + 1;
                }
            }
        }

        // Calcul des taux
        $ageRates = [];
        foreach ($ageCounts as $slice => $count) {
            $ageRates[$slice] = $count > 0 ? (($ageSuccess[$slice] ?? 0) / $count) : 0.0;
        }

        $nbRates = [];
        foreach ($nbCounts as $nb => $count) {
            $nbRates[$nb] = $count > 0 ? (($nbSuccess[$nb] ?? 0) / $count) : 0.0;
        }

        $interestRates = [];
        foreach ($interestCounts as $intId => $count) {
            $interestRates[$intId] = $count > 0 ? (($interestSuccess[$intId] ?? 0) / $count) : 0.0;
        }

        return [
            'avg_rate' => $avgRate,
            'ages' => $ageRates,
            'neighbourhoods' => $nbRates,
            'interests' => $interestRates,
        ];
    }

    /**
     * Score objectif : followers (Plus d'abonnés).
     */
    protected function scoreForFollowersObjective(
        Campaign $campaign,
        User $user,
        ?int $sponsorUserId,
        ?Page $page,
        array $userInterestIds,
        array $targetingInterests
    ): float {
        // Exclusions strictes :
        // 1. Ceux qui suivent déjà le commanditaire
        if ($page) {
            if ($page->isFollowedBy($user)) {
                return 0.0;
            }
        } elseif ($sponsorUserId) {
            if ($user->isFollowing($sponsorUserId)) {
                return 0.0;
            }
        }

        // 2. Ceux qui ont une demande d'abonnement en attente
        if ($sponsorUserId) {
            $hasPendingFollow = FollowRequest::query()
                ->where('from_id', $user->id)
                ->where('to_id', $sponsorUserId)
                ->where('status', 'pending')
                ->exists();
            if ($hasPendingFollow) {
                return 0.0;
            }
        }

        $score = 0.0;

        // +4 par compte suivi par la personne qui suit aussi le commanditaire (amis d'amis)
        if ($sponsorUserId) {
            $myFollowingIds = Follow::query()->where('follower_id', $user->id)->pluck('following_id');
            if ($myFollowingIds->isNotEmpty()) {
                $mutualFollowersCount = Follow::query()
                    ->whereIn('follower_id', $myFollowingIds)
                    ->where('following_id', $sponsorUserId)
                    ->count();
                $score += min(12, $mutualFollowersCount * 4); // Plafonné pour équilibre
            }
        }

        // +3 si elle a vu un contenu du commanditaire sans s'abonner (post_views, reel_views)
        $hasSeenSponsorContent = false;
        if ($sponsorUserId) {
            $hasSeenSponsorPost = PostView::query()
                ->where('user_id', $user->id)
                ->whereHas('post', fn ($q) => $q->where('user_id', $sponsorUserId)->orWhere('page_id', $page?->id))
                ->exists();

            $hasSeenSponsorReel = ReelView::query()
                ->where('user_id', $user->id)
                ->whereHas('reel', fn ($q) => $q->where('user_id', $sponsorUserId)->orWhere('page_id', $page?->id))
                ->exists();

            $hasSeenSponsorContent = $hasSeenSponsorPost || $hasSeenSponsorReel;
        }
        if ($hasSeenSponsorContent) {
            $score += 3.0;
        }

        // +2 par centre d'intérêt commun avec les sujets ciblés par le commanditaire
        $commonInterests = array_intersect($userInterestIds, $targetingInterests);
        $score += (count($commonInterests) * 2.0);

        // +2 si elle s'abonne souvent (abonnements créés ces 30 derniers jours, plafonné)
        $recentFollowsCount = Follow::query()
            ->where('follower_id', $user->id)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
        if ($recentFollowsCount >= 3) {
            $score += 2.0;
        }

        // +1 même quartier
        $sponsorNeighbourhood = $campaign->targeting['neighbourhoods'][0] ?? ($page?->neighbourhood ?? ($campaign->user?->neighbourhood ?? null));
        if ($user->neighbourhood && $sponsorNeighbourhood && strcasecmp($user->neighbourhood, $sponsorNeighbourhood) === 0) {
            $score += 1.0;
        }

        return $score;
    }

    /**
     * Score objectif : messages (Plus de messages).
     */
    protected function scoreForMessagesObjective(
        Campaign $campaign,
        User $user,
        ?int $sponsorUserId,
        ?Page $page,
        mixed $content,
        array $userInterestIds,
        array $targetingInterests
    ): float {
        // Exclusion stricte : Ceux qui ont déjà une conversation ouverte avec le commanditaire sur ce contenu
        $conversationQuery = Conversation::query()
            ->whereHas('members', fn ($q) => $q->where('user_id', $user->id));

        if ($content instanceof Listing) {
            $conversationQuery->where('listing_id', $content->id);
        } else {
            // Pour un post ou reel, vérifier si conversation liée au sponsor avec métadonnées ou listing correspondant
            if ($page) {
                $conversationQuery->where('page_id', $page->id);
            } elseif ($sponsorUserId) {
                $conversationQuery->whereHas('members', fn ($q) => $q->where('user_id', $sponsorUserId));
            }
        }

        if ($conversationQuery->exists()) {
            return 0.0;
        }

        // Score de base initial (pour qu'une campagne éligible sans historique démarre avec un score positif)
        $score = 1.0;

        // +4 si elle écrit des messages (conversations ouvertes ces 30 derniers jours)
        $recentConvs = ConversationMember::query()
            ->where('user_id', $user->id)
            ->where('joined_at', '>=', now()->subDays(30))
            ->count();
        if ($recentConvs > 0) {
            $score += 4.0;
        }

        // Signaux pour un bien immobilier (Listing)
        if ($content instanceof Listing) {
            // +4 pour un bien : biens enregistrés ou aimés du même type, dans la même ville et la même gamme de prix
            $hasMatchingSavedOrLiked = ListingSave::query()
                ->where('user_id', $user->id)
                ->whereHas('listing', function ($q) use ($content) {
                    $q->where('kind', $content->kind)
                        ->where('city', $content->city)
                        ->whereBetween('price', [(float) $content->price * 0.7, (float) $content->price * 1.3]);
                })
                ->exists();

            if (! $hasMatchingSavedOrLiked) {
                $hasMatchingSavedOrLiked = ListingLike::query()
                    ->where('user_id', $user->id)
                    ->whereHas('listing', function ($q) use ($content) {
                        $q->where('kind', $content->kind)
                            ->where('city', $content->city)
                            ->whereBetween('price', [(float) $content->price * 0.7, (float) $content->price * 1.3]);
                    })
                    ->exists();
            }

            if ($hasMatchingSavedOrLiked) {
                $score += 4.0;
            }

            // +3 pour un bien : demande de visite récente ou alerte de recherche qui correspond
            $hasRecentVisitRequest = ListingVisitRequest::query()
                ->where('prospect_id', $user->id)
                ->where('created_at', '>=', now()->subDays(30))
                ->exists();

            $hasMatchingAlert = ListingAlert::query()
                ->where('user_id', $user->id)
                ->where(function ($q) use ($content) {
                    $q->where('city', $content->city)
                        ->orWhereNull('city');
                })
                ->exists();

            if ($hasRecentVisitRequest || $hasMatchingAlert) {
                $score += 3.0;
            }

            // +2 centre d'intérêt realestate pour un bien
            if (in_array('realestate', $userInterestIds, true)) {
                $score += 2.0;
            }
        } else {
            // Pour un autre contenu (Post / Reel) : +2 par centre d'intérêt commun
            $commonInterests = array_intersect($userInterestIds, $targetingInterests);
            $score += (count($commonInterests) * 2.0);
        }

        return $score;
    }

    /**
     * Score objectif : reach (Plus de vues).
     */
    protected function scoreForReachObjective(
        Campaign $campaign,
        User $user,
        mixed $content,
        array $userInterestIds,
        array $targetingInterests
    ): float {
        // Exclusion stricte : Ceux qui l'ont déjà vu (l'objectif reach est d'atteindre de nouvelles personnes)
        if ($content instanceof Post) {
            $hasSeen = PostView::query()->where('post_id', $content->id)->where('user_id', $user->id)->exists();
            if ($hasSeen) {
                return 0.0;
            }
        } elseif ($content instanceof Reel) {
            $hasSeen = ReelView::query()->where('reel_id', $content->id)->where('user_id', $user->id)->exists();
            if ($hasSeen) {
                return 0.0;
            }
        }

        $score = 0.0;

        // +4 si elle n'a jamais vu ce contenu
        $score += 4.0;

        // +3 si elle a été active ces dernières 24 h
        $isActiveRecently = false;
        if ($user->last_seen_at && $user->last_seen_at->isAfter(now()->subHours(24))) {
            $isActiveRecently = true;
        } elseif ($user->updated_at && $user->updated_at->isAfter(now()->subHours(24))) {
            $isActiveRecently = true;
        }
        if ($isActiveRecently) {
            $score += 3.0;
        }

        // +2 par centre d'intérêt commun avec le contenu
        $commonInterests = array_intersect($userInterestIds, $targetingInterests);
        $score += (count($commonInterests) * 2.0);

        // +1 si elle regarde souvent ce type de contenu (reels ou publications)
        if ($content instanceof Post) {
            $viewsCount = PostView::query()->where('user_id', $user->id)->count();
            if ($viewsCount >= 5) {
                $score += 1.0;
            }
        } elseif ($content instanceof Reel) {
            $viewsCount = ReelView::query()->where('user_id', $user->id)->count();
            if ($viewsCount >= 5) {
                $score += 1.0;
            }
        }

        return $score;
    }

    /**
     * Score objectif : engagement (Interactions).
     */
    protected function scoreForEngagementObjective(
        Campaign $campaign,
        User $user,
        ?int $sponsorUserId,
        ?Page $page,
        mixed $content,
        array $userInterestIds,
        array $targetingInterests
    ): float {
        $score = 0.0;

        // +3 si elle aime, commente ou partage souvent (post_likes, reel_reactions, reel_comments, post_shares)
        $likesCount = PostLike::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count();
        $reactionsCount = ReelReaction::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count();
        $commentsCount = PostComment::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count()
            + ReelComment::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count();
        $sharesCount = PostShare::query()->where('user_id', $user->id)->where('created_at', '>=', now()->subDays(30))->count();

        $totalInteractions = $likesCount + $reactionsCount + $commentsCount + $sharesCount;
        if ($totalInteractions >= 3) {
            $score += 3.0;
        }

        // +2 si elle a déjà réagi à un contenu du commanditaire
        $reactedToSponsor = false;
        if ($sponsorUserId || $page) {
            $reactedPost = PostLike::query()
                ->where('user_id', $user->id)
                ->whereHas('post', fn ($q) => $q->where('user_id', $sponsorUserId)->orWhere('page_id', $page?->id))
                ->exists();

            $reactedReel = ReelReaction::query()
                ->where('user_id', $user->id)
                ->whereHas('reel', fn ($q) => $q->where('user_id', $sponsorUserId)->orWhere('page_id', $page?->id))
                ->exists();

            $reactedToSponsor = $reactedPost || $reactedReel;
        }
        if ($reactedToSponsor) {
            $score += 2.0;
        }

        // +2 par centre d'intérêt commun
        $commonInterests = array_intersect($userInterestIds, $targetingInterests);
        $score += (count($commonInterests) * 2.0);

        return $score;
    }

    /**
     * Étape 3 : Calcul du multiplicateur de rythme (pacing).
     * Le rythme vaut > 1 quand la campagne est en retard sur sa dépense prévue, et < 1 quand elle est en avance.
     */
    public function computePacingMultiplier(Campaign $campaign): float
    {
        $budget = (float) $campaign->budget_amount;
        $spent = (float) $campaign->spent_amount;
        $durationDays = max(1, (int) $campaign->duration_days);

        if ($budget <= 0.0) {
            return 1.0;
        }

        // Dépense cible par jour
        $dailyTarget = $budget / $durationDays;

        // Jours écoulés depuis start_at (au moins 1 jour)
        $startAt = $campaign->start_at ? Carbon::parse($campaign->start_at) : ($campaign->created_at ? Carbon::parse($campaign->created_at) : now());
        $elapsedDays = max(1, (int) $startAt->diffInDays(now()) + 1);

        // Dépense attendue à ce stade
        $expectedSpent = min($budget, $dailyTarget * $elapsedDays);

        if ($expectedSpent <= 0.0) {
            return 1.0;
        }

        // Si la campagne est en retard sur sa dépense prévue : rythme > 1
        if ($spent < $expectedSpent) {
            $delayRatio = ($expectedSpent - $spent) / $expectedSpent;

            return 1.0 + min(1.0, $delayRatio); // Entre 1.0 et 2.0
        }

        // Si la campagne est en avance sur sa dépense prévue : rythme < 1
        if ($spent > $expectedSpent) {
            $pacing = $expectedSpent / max(1.0, $spent);

            return max(0.2, min(1.0, $pacing)); // Entre 0.2 et 1.0
        }

        return 1.0;
    }

    /**
     * Sélectionne la meilleure campagne selon score × rythme.
     * Si aucune campagne n'a un score > 0, retourne null.
     */
    public function selectBestCampaign(Collection $campaigns, ?User $user): ?Campaign
    {
        if ($campaigns->isEmpty()) {
            return null;
        }

        $bestCampaign = null;
        $bestFinalScore = 0.0;

        foreach ($campaigns as $campaign) {
            $baseScore = $user ? $this->computeObjectiveScore($campaign, $user) : 1.0;

            // Si le score est <= 0, la campagne est éliminée
            if ($baseScore <= 0.0) {
                continue;
            }

            $pacing = $this->computePacingMultiplier($campaign);
            $finalScore = $baseScore * $pacing;

            if ($finalScore > $bestFinalScore) {
                $bestFinalScore = $finalScore;
                $bestCampaign = $campaign;
            }
        }

        return $bestCampaign;
    }

    /**
     * Enregistre l'affichage pour le plafonnement de fréquence quotidien (3 max / jour).
     */
    public function recordImpressionForCapping(Campaign $campaign, ?User $user): void
    {
        if (! $user) {
            return;
        }

        $today = now()->format('Y-m-d');
        $frequencyKey = "ad_freq:{$campaign->id}:{$user->id}:{$today}";

        $current = (int) Cache::get($frequencyKey, 0);
        Cache::put($frequencyKey, $current + 1, now()->addHours(self::FREQUENCY_CAPPING_HOURS));
    }

    /**
     * Injecte les campagnes sponsorisées de biens immobiliers (Listing) en tête de recherche et du fil Immo.
     */
    public function injectIntoListingFeed(LengthAwarePaginator $paginator, ?User $user = null, array $filters = []): LengthAwarePaginator
    {
        $eligibleCampaigns = $this->getEligibleCampaigns(Listing::class, $user, $filters);
        if ($eligibleCampaigns->isEmpty()) {
            return $paginator;
        }

        // Filtre additionnel : le bien doit correspondre aux critères de recherche s'ils sont fournis
        if (! empty($filters)) {
            $eligibleCampaigns = $eligibleCampaigns->filter(function (Campaign $campaign) use ($filters) {
                /** @var Listing|null $listing */
                $listing = $campaign->boostable;
                if (! $listing) {
                    return false;
                }

                // Critère mode (rent, sale, flatshare)
                if (! empty($filters['mode'])) {
                    $filterMode = is_object($filters['mode']) ? $filters['mode']->value : (string) $filters['mode'];
                    $listingMode = is_object($listing->mode) ? $listing->mode->value : (string) $listing->mode;
                    if (strcasecmp($filterMode, $listingMode) !== 0) {
                        return false;
                    }
                }

                // Critère ville
                if (! empty($filters['city'])) {
                    if (strcasecmp(trim((string) $filters['city']), trim((string) $listing->city)) !== 0) {
                        return false;
                    }
                }

                // Critère quartier
                if (! empty($filters['neighbourhood'])) {
                    if (strcasecmp(trim((string) $filters['neighbourhood']), trim((string) $listing->neighbourhood)) !== 0) {
                        return false;
                    }
                }

                // Critère budget min_price
                $minPrice = $filters['min_price'] ?? ($filters['budget'][0] ?? null);
                if ($minPrice !== null && is_numeric($minPrice) && (float) $minPrice > 0) {
                    if ((float) $listing->price < (float) $minPrice) {
                        return false;
                    }
                }

                // Critère budget max_price
                $maxPrice = $filters['max_price'] ?? ($filters['budget'][1] ?? null);
                if ($maxPrice !== null && is_numeric($maxPrice) && (float) $maxPrice > 0) {
                    if ((float) $listing->price > (float) $maxPrice) {
                        return false;
                    }
                }

                return true;
            })->values();

            if ($eligibleCampaigns->isEmpty()) {
                return $paginator;
            }
        }

        $selectedCampaign = $this->selectBestCampaign($eligibleCampaigns, $user);
        if (! $selectedCampaign) {
            return $paginator;
        }

        /** @var Listing|null $listing */
        $listing = $selectedCampaign->boostable;
        if (! $listing) {
            return $paginator;
        }

        $items = collect($paginator->items());
        $existingListingIds = $items->pluck('id')->map(fn ($id) => (string) $id)->all();

        $sponsorName = $selectedCampaign->page
            ? "Sponsorisé par {$selectedCampaign->page->name}"
            : ($selectedCampaign->metadata['sponsored_by'] ?? ($selectedCampaign->user?->name ?? 'Sponsorisé'));

        // Si le bien est déjà présent organiquement dans la page, on le promeut en tête et on l'annote
        $existingIndex = $items->search(fn ($item) => (string) $item->id === (string) $listing->id);
        if ($existingIndex !== false) {
            $existingItem = $items->pull($existingIndex);
            $existingItem->is_sponsored = true;
            $existingItem->campaign_id = $selectedCampaign->id;
            $existingItem->sponsored_by = $sponsorName;

            $items->prepend($existingItem);

            $this->recordImpressionForCapping($selectedCampaign, $user);

            return new ConcreteLengthAwarePaginator(
                $items->values(),
                $paginator->total(),
                $paginator->perPage(),
                $paginator->currentPage(),
                [
                    'path' => $paginator->path(),
                    'query' => request()->query(),
                ]
            );
        }

        if (in_array((string) $listing->id, $existingListingIds, true)) {
            return $paginator;
        }

        // Marquage publicitaire du modèle
        $listing->is_sponsored = true;
        $listing->campaign_id = $selectedCampaign->id;
        $listing->sponsored_by = $sponsorName;

        // Injecter en tête absolue (position 0)
        $items->splice(0, 0, [$listing]);

        $this->recordImpressionForCapping($selectedCampaign, $user);

        return new ConcreteLengthAwarePaginator(
            $items,
            $paginator->total() + 1,
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * Injecte les campagnes sponsorisées Square dans le paginateur du feed.
     */
    public function injectIntoPostFeed(LengthAwarePaginator $paginator, ?User $user = null, array $context = []): LengthAwarePaginator
    {
        $eligibleCampaigns = $this->getEligibleCampaigns(Post::class, $user, $context);

        // Récupérer également les campagnes actives sur un Listing ayant un post lié
        $eligibleListingCampaigns = $this->getEligibleCampaigns(Listing::class, $user, $context)
            ->filter(function (Campaign $c) {
                /** @var Listing|null $l */
                $l = $c->boostable;
                if (! $l) {
                    return false;
                }
                $sponsorUserId = $c->user_id ?: $l->owner_id ?: $l->created_by_id;
                $pageId = $c->page_id ?: $l->page_id;

                return Post::query()
                    ->where(function ($q) use ($sponsorUserId, $pageId) {
                        if ($pageId) {
                            $q->where('page_id', $pageId);
                        } elseif ($sponsorUserId) {
                            $q->where('user_id', $sponsorUserId);
                        }
                    })
                    ->where('is_archived', false)
                    ->exists();
            });

        $allCampaigns = $eligibleCampaigns->concat($eligibleListingCampaigns);
        if ($allCampaigns->isEmpty()) {
            return $paginator;
        }

        $selectedCampaign = $this->selectBestCampaign($allCampaigns, $user);
        if (! $selectedCampaign) {
            return $paginator;
        }

        /** @var Post|null $post */
        if ($selectedCampaign->boostable instanceof Post) {
            $post = $selectedCampaign->boostable;
        } elseif ($selectedCampaign->boostable instanceof Listing) {
            $listing = $selectedCampaign->boostable;
            $sponsorUserId = $selectedCampaign->user_id ?: $listing->owner_id ?: $listing->created_by_id;
            $pageId = $selectedCampaign->page_id ?: $listing->page_id;

            $post = Post::query()
                ->where(function ($q) use ($sponsorUserId, $pageId) {
                    if ($pageId) {
                        $q->where('page_id', $pageId);
                    } elseif ($sponsorUserId) {
                        $q->where('user_id', $sponsorUserId);
                    }
                })
                ->where('is_archived', false)
                ->latest()
                ->first();
        } else {
            $post = null;
        }

        if (! $post) {
            return $paginator;
        }

        $items = collect($paginator->items());
        $existingPostIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        $branding = [
            'type' => $selectedCampaign->page_id ? 'page' : 'user',
            'id' => $selectedCampaign->page_id ?: $selectedCampaign->user_id,
            'name' => $selectedCampaign->page ? $selectedCampaign->page->name : ($selectedCampaign->user?->name ?? 'Sponsorisé'),
            'handle' => $selectedCampaign->page?->handle ?? $selectedCampaign->user?->handle,
            'avatar' => $selectedCampaign->page?->logo_url ?? $selectedCampaign->user?->avatar_url,
            'is_certified' => $selectedCampaign->page ? $selectedCampaign->page->isCertified() : (bool) ($selectedCampaign->user?->is_verified ?? false),
        ];

        // Si le post est déjà présent organiquement dans la page, on l'annote comme sponsorisé
        $existingIndex = $items->search(fn ($item) => (int) $item->id === (int) $post->id);
        if ($existingIndex !== false) {
            $existingItem = $items->get($existingIndex);
            $existingItem->is_sponsored = true;
            $existingItem->campaign_id = $selectedCampaign->id;
            $existingItem->sponsored_by = $branding['name'];
            $existingItem->sponsor_branding = $branding;

            $this->recordImpressionForCapping($selectedCampaign, $user);

            return $paginator;
        }

        // Déduplication absolue dans la même page
        if (in_array((int) $post->id, $existingPostIds, true)) {
            return $paginator;
        }

        // Marquage publicitaire du modèle
        $post->is_sponsored = true;
        $post->campaign_id = $selectedCampaign->id;
        $post->sponsored_by = $branding['name'];
        $post->sponsor_branding = $branding;

        // Intercalage harmonieux (position 2 de la page)
        $insertIndex = min(2, $items->count());
        $items->splice($insertIndex, 0, [$post]);

        $this->recordImpressionForCapping($selectedCampaign, $user);

        return new ConcreteLengthAwarePaginator(
            $items,
            $paginator->total() + 1,
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'query' => request()->query(),
            ]
        );
    }

    /**
     * Injecte les campagnes sponsorisées Reels dans le paginateur du feed.
     */
    public function injectIntoReelFeed(LengthAwarePaginator $paginator, ?User $user = null, array $context = []): LengthAwarePaginator
    {
        $eligibleCampaigns = $this->getEligibleCampaigns(Reel::class, $user, $context);

        // Récupérer également les campagnes actives sur un Listing qui possède au moins un Reel prêt
        $eligibleListingCampaigns = $this->getEligibleCampaigns(Listing::class, $user, $context)
            ->filter(function (Campaign $c) {
                /** @var Listing|null $l */
                $l = $c->boostable;

                return $l && $l->reels()->where('status', 'ready')->exists();
            });

        $allCampaigns = $eligibleCampaigns->concat($eligibleListingCampaigns);
        if ($allCampaigns->isEmpty()) {
            return $paginator;
        }

        $selectedCampaign = $this->selectBestCampaign($allCampaigns, $user);
        if (! $selectedCampaign) {
            return $paginator;
        }

        /** @var Reel|null $reel */
        if ($selectedCampaign->boostable instanceof Reel) {
            $reel = $selectedCampaign->boostable;
        } elseif ($selectedCampaign->boostable instanceof Listing) {
            $listing = $selectedCampaign->boostable;
            $reel = $listing->reels()->where('status', 'ready')->first();
        } else {
            $reel = null;
        }

        if (! $reel) {
            return $paginator;
        }

        $items = collect($paginator->items());
        $existingReelIds = $items->pluck('id')->map(fn ($id) => (string) $id)->all();

        $branding = [
            'type' => $selectedCampaign->page_id ? 'page' : 'user',
            'id' => $selectedCampaign->page_id ?: $selectedCampaign->user_id,
            'name' => $selectedCampaign->page ? $selectedCampaign->page->name : ($selectedCampaign->user?->name ?? 'Sponsorisé'),
            'handle' => $selectedCampaign->page?->handle ?? $selectedCampaign->user?->handle,
            'avatar' => $selectedCampaign->page?->logo_url ?? $selectedCampaign->user?->avatar_url,
            'is_certified' => $selectedCampaign->page ? $selectedCampaign->page->isCertified() : (bool) ($selectedCampaign->user?->is_verified ?? false),
        ];

        // Si le reel est déjà présent organiquement, on l'annote comme sponsorisé
        $existingIndex = $items->search(fn ($item) => (string) $item->id === (string) $reel->id);
        if ($existingIndex !== false) {
            $existingItem = $items->get($existingIndex);
            $existingItem->is_sponsored = true;
            $existingItem->campaign_id = $selectedCampaign->id;
            $existingItem->sponsored_by = $branding['name'];
            $existingItem->sponsor_branding = $branding;

            $this->recordImpressionForCapping($selectedCampaign, $user);

            return $paginator;
        }

        if (in_array((string) $reel->id, $existingReelIds, true)) {
            return $paginator;
        }

        $reel->is_sponsored = true;
        $reel->campaign_id = $selectedCampaign->id;
        $reel->sponsored_by = $branding['name'];
        $reel->sponsor_branding = $branding;

        $insertIndex = min(1, $items->count());
        $items->splice($insertIndex, 0, [$reel]);

        $this->recordImpressionForCapping($selectedCampaign, $user);

        return new ConcreteLengthAwarePaginator(
            $items,
            $paginator->total() + 1,
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'query' => request()->query(),
            ]
        );
    }
}
