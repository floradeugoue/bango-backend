<?php

namespace App\Services\Boost;

use App\Models\Boost\CampaignImpression;
use App\Models\Boost\CampaignResult;
use App\Models\User;
use Carbon\Carbon;

class CampaignAttributionService
{
    /**
     * Fenêtre d'attribution post-impression (24 heures).
     */
    public const ATTRIBUTION_WINDOW_HOURS = 24;

    /**
     * Attribue un abonnement au commanditaire si l'utilisateur a vu une campagne de ce commanditaire dans les 24h.
     */
    public function attributeFollow(User $subscriber, User|int $sponsor): void
    {
        $sponsorId = $sponsor instanceof User ? $sponsor->id : (int) $sponsor;

        // Trouver les campagnes actives ou récentes de ce sponsor vues par $subscriber dans les 24h
        $cutoff = Carbon::now()->subHours(self::ATTRIBUTION_WINDOW_HOURS);

        $impression = CampaignImpression::query()
            ->where('user_id', $subscriber->id)
            ->where('shown_at', '>=', $cutoff)
            ->whereHas('campaign', function ($query) use ($sponsorId) {
                $query->where('user_id', $sponsorId)
                    ->orWhereHas('page', fn ($q) => $q->where('owner_id', $sponsorId));
            })
            ->latest('shown_at')
            ->first();

        if ($impression) {
            CampaignResult::firstOrCreate([
                'campaign_id' => $impression->campaign_id,
                'user_id' => $subscriber->id,
                'type' => 'follow',
                'impression_id' => $impression->id,
            ], [
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Attribue l'envoi d'un message direct ou d'une demande de contact au commanditaire.
     */
    public function attributeMessage(User $sender, User|int $sponsor, ?string $listingId = null): void
    {
        $sponsorId = $sponsor instanceof User ? $sponsor->id : (int) $sponsor;
        $cutoff = Carbon::now()->subHours(self::ATTRIBUTION_WINDOW_HOURS);

        $query = CampaignImpression::query()
            ->where('user_id', $sender->id)
            ->where('shown_at', '>=', $cutoff)
            ->whereHas('campaign', function ($query) use ($sponsorId, $listingId) {
                $query->where(function ($q) use ($sponsorId) {
                    $q->where('user_id', $sponsorId)
                        ->orWhereHas('page', fn ($p) => $p->where('owner_id', $sponsorId));
                });

                if ($listingId) {
                    $query->orWhere(function ($q) use ($listingId) {
                        $q->where('boostable_type', 'App\\Models\\Listing\\Listing')
                            ->where('boostable_id', $listingId);
                    });
                }
            });

        $impression = $query->latest('shown_at')->first();

        if ($impression) {
            CampaignResult::firstOrCreate([
                'campaign_id' => $impression->campaign_id,
                'user_id' => $sender->id,
                'type' => 'message',
                'impression_id' => $impression->id,
            ], [
                'occurred_at' => now(),
            ]);
        }
    }

    /**
     * Attribue la visite d'un profil ou d'une Page Pro au commanditaire.
     */
    public function attributeProfileVisit(User $viewer, User|int $sponsor): void
    {
        $sponsorId = $sponsor instanceof User ? $sponsor->id : (int) $sponsor;
        if ((int) $viewer->id === (int) $sponsorId) {
            return;
        }

        $cutoff = Carbon::now()->subHours(self::ATTRIBUTION_WINDOW_HOURS);

        $impression = CampaignImpression::query()
            ->where('user_id', $viewer->id)
            ->where('shown_at', '>=', $cutoff)
            ->whereHas('campaign', function ($query) use ($sponsorId) {
                $query->where('user_id', $sponsorId)
                    ->orWhereHas('page', fn ($q) => $q->where('owner_id', $sponsorId));
            })
            ->latest('shown_at')
            ->first();

        if ($impression) {
            CampaignResult::firstOrCreate([
                'campaign_id' => $impression->campaign_id,
                'user_id' => $viewer->id,
                'type' => 'profile_visit',
                'impression_id' => $impression->id,
            ], [
                'occurred_at' => now(),
            ]);
        }
    }
}
