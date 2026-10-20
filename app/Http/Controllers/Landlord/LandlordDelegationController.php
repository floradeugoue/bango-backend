<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\InviteAgentRequest;
use App\Http\Resources\Delegation\DelegationResource;
use App\Models\Delegation\Delegation;
use App\Services\Delegation\DelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class LandlordDelegationController extends Controller
{
    public function __construct(
        protected DelegationService $delegationService
    ) {}

    /**
     * Liste des mandats et délégations émis par le bailleur.
     * GET /api/landlord/delegations
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $landlord = $request->user();

        $delegations = Delegation::where('bailleur_id', $landlord->id)
            ->with(['agent', 'listing'])
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return DelegationResource::collection($delegations);
    }

    /**
     * Inviter un agent immobilier pour un bien déterminé (AG-02).
     * POST /api/landlord/delegations
     */
    public function store(InviteAgentRequest $request): JsonResponse
    {
        $delegation = $this->delegationService->inviteAgent(
            $request->user(),
            $request->validated()
        );

        return (new DelegationResource($delegation))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Révoquer un mandat ou annuler une invitation (AG-03).
     * DELETE /api/landlord/delegations/{id}
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $revoked = $this->delegationService->revokeDelegation(
            $delegation,
            $request->user(),
            $request->input('reason')
        );

        return (new DelegationResource($revoked))->response();
    }

    /**
     * Relance un agent pour une invitation en attente.
     * POST /api/landlord/delegations/{id}/remind
     */
    public function remind(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $reminded = $this->delegationService->remindInvitation($delegation, $request->user());

        return (new DelegationResource($reminded))->response();
    }

    /**
     * Modifie les capacités ou l'expiration d'une délégation.
     * PUT /api/landlord/delegations/{id}
     */
    public function update(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $validated = $request->validate([
            'capabilities' => ['sometimes', 'array'],
            'capabilities.*' => ['string', 'in:visits,reply,edit'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $updated = $this->delegationService->updateDelegation($delegation, $request->user(), $validated);

        return (new DelegationResource($updated))->response();
    }

    /**
     * Rétablit un mandat révoqué ou expiré.
     * POST /api/landlord/delegations/{id}/reinstate
     */
    public function reinstate(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $reinstated = $this->delegationService->reinstateDelegation($delegation, $request->user());

        return (new DelegationResource($reinstated))->response();
    }
}
