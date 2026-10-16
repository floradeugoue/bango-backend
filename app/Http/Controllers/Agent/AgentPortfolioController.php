<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Resources\Agent\AgentPropertyResource;
use App\Http\Resources\Agent\AgentProspectResource;
use App\Services\Delegation\DelegationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentPortfolioController extends Controller
{
    public function __construct(
        protected DelegationService $delegationService
    ) {}

    /**
     * Portefeuille des biens immobiliers délégués à l'agent (AG-01).
     * GET /api/agent/properties
     */
    public function properties(Request $request): AnonymousResourceCollection
    {
        $agent = $request->user();
        $perPage = $request->integer('per_page', 15);

        $listings = $this->delegationService->getAgentProperties(
            $agent,
            $request->query(),
            $perPage
        );

        return AgentPropertyResource::collection($listings);
    }

    /**
     * Prospects et contacts des biens mandatés de l'agent (AG-05).
     * GET /api/agent/prospects
     */
    public function prospects(Request $request): AnonymousResourceCollection
    {
        $agent = $request->user();
        $perPage = $request->integer('per_page', 15);

        $reservations = $this->delegationService->getAgentProspects(
            $agent,
            $request->query(),
            $perPage
        );

        return AgentProspectResource::collection($reservations);
    }
}
