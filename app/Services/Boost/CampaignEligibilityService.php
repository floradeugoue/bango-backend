<?php

namespace App\Services\Boost;

use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Page\Page;
use App\Models\User;
use App\Services\Subscription\BadgeResolutionService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class CampaignEligibilityService
{
    public function __construct(
        protected BadgeResolutionService $badgeResolutionService
    ) {}

    /**
     * Vérifie de manière centralisée et unique si l'utilisateur ou la Page Pro est éligible
     * à démarrer ou créer une campagne publicitaire (Gate `boost.start`).
     */
    public function checkEligibility(
        User $user,
        ?Model $content = null,
        ?Page $page = null
    ): Response {
        // 1. Contrôle du compte utilisateur (absence de suspension ou sanction)
        if ($user->status === 'suspended') {
            return Response::deny('Votre compte utilisateur est suspendu. Vous ne pouvez pas lancer de campagne.');
        }

        // 2. Détection du contexte : Page Professionnelle explicite ou via le contenu
        if ($page !== null) {
            return $this->checkPageEligibility($user, $page, $content);
        }

        if ($content !== null && ! empty($content->page_id)) {
            $contentPage = Page::find($content->page_id);
            if ($contentPage) {
                return $this->checkPageEligibility($user, $contentPage, $content);
            }
        }

        // 3. Contexte Compte Personnel
        return $this->checkPersonalEligibility($user, $content);
    }

    /**
     * Vérifie l'éligibilité au sponsoring au nom d'une Page Professionnelle.
     */
    public function checkPageEligibility(User $user, Page $page, ?Model $content = null): Response
    {
        // La Page Pro doit être active
        if (! $page->isActive()) {
            return Response::deny('Cette Page Professionnelle est suspendue ou inactive. Le sponsoring est refusé.');
        }

        // L'utilisateur doit être membre actif et autorisé (Owner ou Editor)
        if (! $page->canUserPublish($user)) {
            return Response::deny('Vous n\'avez pas les permissions suffisantes (Propriétaire ou Éditeur) sur cette Page Professionnelle.');
        }

        // Si un contenu est fourni, vérifier qu'il est rattaché à la Page
        if ($content !== null) {
            if ($content instanceof Page && (int) $content->id !== (int) $page->id) {
                return Response::deny('Le contenu sélectionné ne correspond pas à la Page Professionnelle commanditaire.');
            }

            if (! ($content instanceof Page) && (int) ($content->page_id ?? 0) !== (int) $page->id) {
                return Response::deny('Le contenu à sponsoriser n\'appartient pas à cette Page Professionnelle.');
            }
        }

        return Response::allow();
    }

    /**
     * Vérifie si un utilisateur dispose du badge Agent actif et certifié.
     */
    public function hasActiveAgentBadge(User $agent): bool
    {
        if ($agent->isAdmin()) {
            return true;
        }

        if ($agent->locked_until && $agent->locked_until->isFuture()) {
            return false;
        }

        if ($agent->suspended_until && $agent->suspended_until->isFuture()) {
            return false;
        }

        if ($agent->verification_lapsed || ! $agent->is_verified) {
            return false;
        }

        if (in_array($agent->badge, ['agent', 'double'], true)) {
            return true;
        }

        $badgeStatus = $this->badgeResolutionService->resolveUserBadges($agent);

        return (bool) ($badgeStatus['hasAgent'] ?? false);
    }

    /**
     * Vérifie l'éligibilité au boost d'un compte personnel (règle produit : le boost cible du contenu).
     */
    public function checkPersonalEligibility(User $user, ?Model $content = null): Response
    {
        // Règle produit BANGO : Le boost cible un contenu (annonce, publication, reel),
        // et n'est pas restreint aux seuls professionnels certifiés pour posts et reels.
        // Tout utilisateur actif et non suspendu peut promouvoir ses propres contenus.
        if ($user->status === 'suspended') {
            return Response::deny('Votre compte utilisateur est suspendu. Vous ne pouvez pas lancer de campagne.');
        }

        // Si un contenu est fourni
        if ($content !== null) {
            if ($content instanceof Listing) {
                // Pour boostable_type = listing : autoriser si badge Agent actif ET (propriétaire OU délégation active sur le bien)
                $hasAgentBadge = $this->hasActiveAgentBadge($user);

                if (! $hasAgentBadge) {
                    return Response::denyWithStatus(403, 'Seuls les agents immobiliers actifs peuvent booster une annonce immobilière.', 'listing_boost_agents_only');
                }

                $isOwner = (int) $content->owner_id === (int) $user->id;
                $hasActiveDelegation = Delegation::where('listing_id', $content->id)
                    ->where('agent_id', $user->id)
                    ->active()
                    ->exists();

                if (! $isOwner && ! $hasActiveDelegation) {
                    return Response::denyWithStatus(403, 'Vous ne pouvez booster que vos propres annonces ou les biens qui vous sont confiés en mandat actif.', 'listing_boost_agents_only');
                }
            } else {
                $contentUserId = (int) ($content->user_id ?? $content->owner_id ?? 0);
                if ($contentUserId !== (int) $user->id) {
                    return Response::deny('Vous ne pouvez promouvoir que vos propres contenus.');
                }
            }
        }

        return Response::allow();
    }
}
