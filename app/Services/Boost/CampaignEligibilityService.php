<?php

namespace App\Services\Boost;

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

        // La Page Pro doit être certifiée (statut approved)
        if (! $page->isCertified()) {
            return Response::deny('La Page Professionnelle doit être certifiée pour pouvoir lancer des campagnes publicitaires.');
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
     * Vérifie l'éligibilité au boost d'un compte personnel (règles SUB, BADGE et KYC).
     */
    public function checkPersonalEligibility(User $user, ?Model $content = null): Response
    {
        // 1. Résolution des badges réels via le service officiel BANGO
        $badgeStatus = $this->badgeResolutionService->resolveUserBadges($user);

        // 2. Vérification des conditions SUB et KYC
        // Un compte personnel DOIT posséder un badge professionnel actif (Bailleur ou Agent)
        // soutenu par un abonnement valide ou en grâce, et un KYC certifié non caduc.
        $hasActiveBadge = $badgeStatus['hasLandlord'] || $badgeStatus['hasAgent'];

        if (! $hasActiveBadge) {
            // Identifier précisément la cause du refus pour un message explicite
            $hasSub = $user->hasActiveSubscription('landlord') || $user->hasActiveSubscription('agent');

            if ($hasSub) {
                if (! $user->is_verified) {
                    return Response::deny('Votre abonnement est actif mais votre identité (KYC) n\'est pas encore validée. Le boost est bloqué.');
                }
                if ($badgeStatus['verificationLapsed']) {
                    return Response::deny('Votre vérification d\'identité a expiré (statut caduc). Veuillez renouveler votre KYC pour booster vos contenus.');
                }
            }

            return Response::deny('Le lancement de campagnes de boost est réservé aux professionnels certifiés disposant d\'un abonnement actif (Bailleur ou Agent) et d\'un badge vérifié.');
        }

        // 3. Si un contenu est fourni, vérifier la propriété directe
        if ($content !== null) {
            $contentUserId = (int) ($content->user_id ?? $content->owner_id ?? 0);
            if ($contentUserId !== (int) $user->id) {
                return Response::deny('Vous ne pouvez promouvoir que vos propres contenus.');
            }
        }

        return Response::allow();
    }
}
