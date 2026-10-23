<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\Delegation\DelegationResource;
use App\Models\Delegation\Delegation;
use App\Services\Delegation\DelegationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentInvitationController extends Controller
{
    public function __construct(
        protected DelegationService $delegationService
    ) {}

    /**
     * Liste des invitations de mandat reçues par l'agent.
     * GET /api/agent/invitations
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $agent = $request->user();

        $invitations = Delegation::where('agent_id', $agent->id)
            ->whereHas('listing')
            ->with(['bailleur', 'listing'])
            ->latest('invited_at')
            ->paginate($request->integer('per_page', 20));

        return DelegationResource::collection($invitations);
    }

    /**
     * Accepter une invitation de mandat (AG-04).
     * POST /api/agent/invitations/{id}/accept
     */
    public function accept(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $accepted = $this->delegationService->acceptInvitation(
            $delegation,
            $request->user()
        );

        return (new DelegationResource($accepted))->response();
    }

    /**
     * Refuser une invitation de mandat (AG-04).
     * POST /api/agent/invitations/{id}/reject
     */
    public function reject(Request $request, string $id): JsonResponse
    {
        /** @var Delegation $delegation */
        $delegation = Delegation::findOrFail($id);

        $declined = $this->delegationService->rejectInvitation(
            $delegation,
            $request->user()
        );

        return (new DelegationResource($declined))->response();
    }
}
