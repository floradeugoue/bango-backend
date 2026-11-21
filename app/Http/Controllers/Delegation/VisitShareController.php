<?php

namespace App\Http\Controllers\Delegation;

use App\Enums\Delegation\DelegationStatus;
use App\Http\Controllers\Controller;
use App\Models\Delegation\Delegation;
use App\Models\Delegation\VisitShare;
use App\Models\User;
use App\Notifications\Delegation\VisitShareNegotiationNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VisitShareController extends Controller
{
    /**
     * Proposer un pourcentage sur les frais de visite (bailleur ou agent).
     * POST /api/delegations/visit-share
     */
    public function propose(Request $request): JsonResponse
    {
        $user = $request->user();
        $activePage = $request->attributes->get('active_page');

        $validated = $request->validate([
            'percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'agent_id' => ['required_without:bailleur_id', 'nullable', 'integer', 'exists:users,id'],
            'bailleur_id' => ['required_without:agent_id', 'nullable', 'integer', 'exists:users,id'],
        ]);

        $percent = (float) $validated['percent'];

        if (! empty($validated['agent_id'])) {
            // L'utilisateur connecté est le bailleur qui propose à un agent
            $landlordId = $user->id;
            $agentId = (int) $validated['agent_id'];
            $offerBy = 'landlord';
            $recipient = User::findOrFail($agentId);
        } else {
            // L'utilisateur connecté est l'agent qui propose à un bailleur
            $agentId = $user->id;
            $landlordId = (int) $validated['bailleur_id'];
            $offerBy = 'agent';
            $recipient = User::findOrFail($landlordId);
        }

        if (! $this->hasActiveOrPendingDelegation($landlordId, $agentId)) {
            return response()->json([
                'success' => false,
                'code' => 'NO_ACTIVE_DELEGATION',
                'kind' => 'NO_ACTIVE_DELEGATION',
                'message' => 'Aucune délégation active ou en attente ne lie ce bailleur et cet agent.',
            ], 403);
        }

        $existingShare = VisitShare::where('landlord_id', $landlordId)
            ->where('agent_id', $agentId)
            ->first();
        $previousOfferBy = $existingShare?->offer_by;

        $share = VisitShare::updateOrCreate(
            ['landlord_id' => $landlordId, 'agent_id' => $agentId],
            [
                'offer_percent' => $percent,
                'offer_by' => $offerBy,
                'offer_at' => now(),
            ]
        );

        // Détection : l'offre part-elle avec une invitation initiale ?
        // Ne pas envoyer visit_share_offer quand l'offre part avec une invitation.
        // La garder pour une contre-proposition faite après coup.
        $isExplicitInvitationOffer = $request->boolean('with_invitation')
            || $request->boolean('is_invitation')
            || $request->boolean('from_invitation');

        $isPendingInvitationOffer = false;
        if ($offerBy === 'landlord' && $previousOfferBy !== 'agent') {
            $hasPendingDelegation = Delegation::query()
                ->where('bailleur_id', $landlordId)
                ->where('agent_id', $agentId)
                ->where('status', DelegationStatus::Pending)
                ->whereNull('revoked_at')
                ->exists();

            if ($hasPendingDelegation) {
                $isPendingInvitationOffer = true;
            }
        }

        $shouldNotifyOffer = ! ($isExplicitInvitationOffer || $isPendingInvitationOffer);

        if ($shouldNotifyOffer) {
            // Notifier l'autre côté avec page_id si actif
            try {
                $recipient->notify(new VisitShareNegotiationNotification(
                    visitShare: $share,
                    event: 'offer',
                    pageId: $activePage?->id
                ));
            } catch (\Throwable $e) {
                // Continuer sans bloquer
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Proposition enregistrée avec succès.',
            'data' => $share,
        ], 200);
    }

    /**
     * Accepter une proposition de pourcentage sur les frais de visite.
     * POST /api/delegations/visit-share/accept
     */
    public function accept(Request $request): JsonResponse
    {
        $user = $request->user();
        $activePage = $request->attributes->get('active_page');

        $validated = $request->validate([
            'agent_id' => ['required_without:bailleur_id', 'nullable', 'integer', 'exists:users,id'],
            'bailleur_id' => ['required_without:agent_id', 'nullable', 'integer', 'exists:users,id'],
        ]);

        if (! empty($validated['agent_id'])) {
            // L'utilisateur connecté est le bailleur acceptant une offre faite par l'agent
            $landlordId = $user->id;
            $agentId = (int) $validated['agent_id'];
            $share = VisitShare::where('landlord_id', $landlordId)->where('agent_id', $agentId)->first();

            if (! $this->hasActiveOrPendingDelegation($landlordId, $agentId)) {
                return response()->json([
                    'success' => false,
                    'code' => 'NO_ACTIVE_DELEGATION',
                    'kind' => 'NO_ACTIVE_DELEGATION',
                    'message' => 'Aucune délégation active ou en attente ne lie ce bailleur et cet agent.',
                ], 403);
            }

            if (! $share) {
                return response()->json(['message' => 'Aucune proposition trouvée.'], 404);
            }

            if ($share->offer_by !== 'agent') {
                return response()->json([
                    'message' => 'Seul l\'agent peut accepter cette proposition car vous l\'avez émise.',
                ], 403);
            }

            $recipient = User::findOrFail($agentId);
        } else {
            // L'utilisateur connecté est l'agent acceptant une offre faite par le bailleur
            $agentId = $user->id;
            $landlordId = (int) $validated['bailleur_id'];
            $share = VisitShare::where('landlord_id', $landlordId)->where('agent_id', $agentId)->first();

            if (! $this->hasActiveOrPendingDelegation($landlordId, $agentId)) {
                return response()->json([
                    'success' => false,
                    'code' => 'NO_ACTIVE_DELEGATION',
                    'kind' => 'NO_ACTIVE_DELEGATION',
                    'message' => 'Aucune délégation active ou en attente ne lie ce bailleur et cet agent.',
                ], 403);
            }

            if (! $share) {
                return response()->json(['message' => 'Aucune proposition trouvée.'], 404);
            }

            if ($share->offer_by !== 'landlord') {
                return response()->json([
                    'message' => 'Seul le bailleur peut accepter cette proposition car vous l\'avez émise.',
                ], 403);
            }

            $recipient = User::findOrFail($landlordId);
        }

        $share->agreed_percent = $share->offer_percent;
        $share->save();

        // Notifier l'autre côté de l'accord
        try {
            $recipient->notify(new VisitShareNegotiationNotification(
                visitShare: $share,
                event: 'agreed',
                pageId: $activePage?->id
            ));
        } catch (\Throwable $e) {
            // Ignore
        }

        return response()->json([
            'success' => true,
            'message' => 'Proposition acceptée. L\'accord est désormais actif.',
            'data' => $share,
        ], 200);
    }

    /**
     * Vérifie s'il existe une délégation en attente ou acceptée reliant le bailleur et l'agent.
     * Une délégation révoquée, refusée ou expirée ne permet pas la négociation.
     */
    protected function hasActiveOrPendingDelegation(int $landlordId, int $agentId): bool
    {
        return Delegation::query()
            ->where('bailleur_id', $landlordId)
            ->where('agent_id', $agentId)
            ->whereNull('revoked_at')
            ->whereIn('status', [DelegationStatus::Pending, DelegationStatus::Active])
            ->where(function ($query) {
                $query->where('status', DelegationStatus::Active)
                    ->orWhere(function ($q) {
                        $q->where('status', DelegationStatus::Pending)
                            ->where(function ($sub) {
                                $sub->whereNull('expires_at')
                                    ->orWhere('expires_at', '>', now());
                            });
                    });
            })
            ->exists();
    }
}
