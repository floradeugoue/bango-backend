<?php

namespace App\Services\Boost;

use App\Models\Boost\Campaign;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;

class AdDeliveryService
{
    /**
     * Récupère les campagnes actives éligibles à l'injection publicitaire dans un feed.
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
            ->orderBy('created_at')
            ->get();

        // Consentement LG : vérifier si l'utilisateur autorise le profilage personnalisé
        $allowsPersonalization = true;
        if ($user) {
            $privacySettings = $user->privacy_settings ?? [];
            $allowsPersonalization = (bool) ($privacySettings['ads_personalization'] ?? true);
        }

        $userCity = $user?->city ?? ($context['city'] ?? null);

        // 2. Filtrage métier strict
        return $campaigns->filter(function (Campaign $campaign) use ($userCity, $allowsPersonalization) {
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

            // D. Ciblage géographique et consentement LG
            $targeting = $campaign->targeting ?? [];

            // Si la campagne cible une ville
            if (! empty($targeting['city'])) {
                if (! $userCity) {
                    return false;
                }
                if (strcasecmp($targeting['city'], $userCity) !== 0) {
                    return false;
                }
            }

            // Si le profilage personnalisé est refusé par l'utilisateur (RGPD / LG)
            if (! $allowsPersonalization) {
                if (! empty($targeting['age_min']) || ! empty($targeting['age_max']) || ! empty($targeting['interests'])) {
                    return false;
                }
            }

            return true;
        })->values();
    }

    /**
     * Injecte les campagnes sponsorisées Square dans le paginateur du feed.
     */
    public function injectIntoPostFeed(LengthAwarePaginator $paginator, ?User $user = null, array $context = []): LengthAwarePaginator
    {
        $eligibleCampaigns = $this->getEligibleCampaigns(Post::class, $user, $context);
        if ($eligibleCampaigns->isEmpty()) {
            return $paginator;
        }

        $items = collect($paginator->items());
        $existingPostIds = $items->pluck('id')->map(fn ($id) => (int) $id)->all();

        $injectedCount = 0;
        foreach ($eligibleCampaigns as $campaign) {
            /** @var Post|null $post */
            $post = $campaign->boostable;
            if (! $post) {
                continue;
            }

            $branding = [
                'type' => $campaign->page_id ? 'page' : 'user',
                'id' => $campaign->page_id ?: $campaign->user_id,
                'name' => $campaign->page ? $campaign->page->name : ($campaign->user?->name ?? 'Sponsorisé'),
                'handle' => $campaign->page?->handle ?? $campaign->user?->handle,
                'avatar' => $campaign->page?->logo_url ?? $campaign->user?->avatar_url,
                'is_certified' => $campaign->page ? $campaign->page->isCertified() : (bool) ($campaign->user?->is_verified ?? false),
            ];

            // Si le post est déjà présent organiquement dans la page, on l'annote comme sponsorisé
            $existingIndex = $items->search(fn ($item) => (int) $item->id === (int) $post->id);
            if ($existingIndex !== false) {
                $existingItem = $items->get($existingIndex);
                $existingItem->is_sponsored = true;
                $existingItem->campaign_id = $campaign->id;
                $existingItem->sponsored_by = $branding['name'];
                $existingItem->sponsor_branding = $branding;
                $injectedCount++;

                if ($injectedCount >= 1) {
                    break;
                }

                continue;
            }

            // Déduplication absolue dans la même page
            if (in_array((int) $post->id, $existingPostIds, true)) {
                continue;
            }

            // Marquage publicitaire du modèle
            $post->is_sponsored = true;
            $post->campaign_id = $campaign->id;
            $post->sponsored_by = $branding['name'];
            $post->sponsor_branding = $branding;

            // Intercalage harmonieux (position 2 de la page)
            $insertIndex = min(2, $items->count());
            $items->splice($insertIndex, 0, [$post]);
            $existingPostIds[] = (int) $post->id;
            $injectedCount++;

            if ($injectedCount >= 1) {
                break;
            }
        }

        return new ConcreteLengthAwarePaginator(
            $items,
            $paginator->total() + $injectedCount,
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
        if ($eligibleCampaigns->isEmpty()) {
            return $paginator;
        }

        $items = collect($paginator->items());
        $existingReelIds = $items->pluck('id')->map(fn ($id) => (string) $id)->all();

        $injectedCount = 0;
        foreach ($eligibleCampaigns as $campaign) {
            /** @var Reel|null $reel */
            $reel = $campaign->boostable;
            if (! $reel) {
                continue;
            }

            $branding = [
                'type' => $campaign->page_id ? 'page' : 'user',
                'id' => $campaign->page_id ?: $campaign->user_id,
                'name' => $campaign->page ? $campaign->page->name : ($campaign->user?->name ?? 'Sponsorisé'),
                'handle' => $campaign->page?->handle ?? $campaign->user?->handle,
                'avatar' => $campaign->page?->logo_url ?? $campaign->user?->avatar_url,
                'is_certified' => $campaign->page ? $campaign->page->isCertified() : (bool) ($campaign->user?->is_verified ?? false),
            ];

            // Si le reel est déjà présent organiquement, on l'annote comme sponsorisé
            $existingIndex = $items->search(fn ($item) => (string) $item->id === (string) $reel->id);
            if ($existingIndex !== false) {
                $existingItem = $items->get($existingIndex);
                $existingItem->is_sponsored = true;
                $existingItem->campaign_id = $campaign->id;
                $existingItem->sponsored_by = $branding['name'];
                $existingItem->sponsor_branding = $branding;
                $injectedCount++;

                if ($injectedCount >= 1) {
                    break;
                }

                continue;
            }

            if (in_array((string) $reel->id, $existingReelIds, true)) {
                continue;
            }

            $reel->is_sponsored = true;
            $reel->campaign_id = $campaign->id;
            $reel->sponsored_by = $branding['name'];
            $reel->sponsor_branding = $branding;

            $insertIndex = min(1, $items->count());
            $items->splice($insertIndex, 0, [$reel]);
            $existingReelIds[] = (string) $reel->id;
            $injectedCount++;

            if ($injectedCount >= 1) {
                break;
            }
        }

        return new ConcreteLengthAwarePaginator(
            $items,
            $paginator->total() + $injectedCount,
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'query' => request()->query(),
            ]
        );
    }
}
