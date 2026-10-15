<?php

namespace App\Policies;

use App\Enums\Listing\ListingStatus;
use App\Models\Listing\Listing;
use App\Models\User;
use App\Services\Listing\ListingService;

class ListingPolicy
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Détermine si l'utilisateur peut voir la liste des annonces publiques.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Détermine si l'utilisateur peut voir la fiche détaillée d'une annonce.
     */
    public function view(?User $user, Listing $listing): bool
    {
        // 1. L'auteur (propriétaire), l'agent gestionnaire ou l'admin ont toujours accès
        if ($user && ($user->isAdmin() || $user->id === $listing->owner_id || ($listing->agent_id && $user->id === $listing->agent_id))) {
            return true;
        }

        // 2. Si le propriétaire ou l'utilisateur se sont mutuellement bloqués, refuser
        if ($user && ($user->hasBlocked($listing->owner_id) || $user->isBlockedBy($listing->owner_id))) {
            return false;
        }

        // 3. Les brouillons ou annonces en cours d'examen ne sont JAMAIS visibles par les tiers
        if (in_array($listing->status, [ListingStatus::Draft, ListingStatus::Review, ListingStatus::Rejected], true)) {
            return false;
        }

        // 4. Les annonces publiées, réservées ou prises (taken) sont consultables
        return true;
    }

    /**
     * Détermine si l'utilisateur peut créer ou publier une annonce.
     */
    public function create(User $user): bool
    {
        return $this->listingService->canPublish($user);
    }

    /**
     * Détermine si l'utilisateur peut modifier une annonce.
     */
    public function update(User $user, Listing $listing): bool
    {
        return $this->listingService->canManage($user, $listing);
    }

    /**
     * Détermine si l'utilisateur peut supprimer (soft delete) une annonce.
     */
    public function delete(User $user, Listing $listing): bool
    {
        return $user->isAdmin() || $user->id === $listing->owner_id;
    }
}
