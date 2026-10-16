<?php

namespace App\Policies;

use App\Models\Boost\Campaign;
use App\Models\Page\Page;
use App\Models\User;
use App\Services\Boost\CampaignEligibilityService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class CampaignPolicy
{
    /**
     * Détermine si l'utilisateur peut lister ses campagnes.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Détermine si l'utilisateur peut consulter les détails et métriques de la campagne.
     * Empêche strictement l'IDOR et la fuite de données vers des tiers.
     */
    public function view(User $user, Campaign $campaign): bool
    {
        if ((int) $campaign->user_id === (int) $user->id) {
            return true;
        }

        if ($campaign->page_id && $campaign->page) {
            return $campaign->page->canUserPublish($user);
        }

        return false;
    }

    /**
     * Détermine si l'utilisateur peut créer une campagne (raccordé à l'éligibilité).
     */
    public function create(User $user, ?Model $content = null, ?Page $page = null): Response|bool
    {
        return app(CampaignEligibilityService::class)->checkEligibility($user, $content, $page);
    }

    /**
     * Détermine si l'utilisateur peut modifier, mettre en pause ou annuler la campagne.
     */
    public function update(User $user, Campaign $campaign): bool
    {
        if ((int) $campaign->user_id === (int) $user->id) {
            return true;
        }

        if ($campaign->page_id && $campaign->page) {
            return $campaign->page->canUserPublish($user);
        }

        return false;
    }

    /**
     * Détermine si l'utilisateur peut supprimer la campagne.
     */
    public function delete(User $user, Campaign $campaign): bool
    {
        if ((int) $campaign->user_id === (int) $user->id) {
            return true;
        }

        if ($campaign->page_id && $campaign->page) {
            return $campaign->page->isOwner($user);
        }

        return false;
    }
}
