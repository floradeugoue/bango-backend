<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\SearchAgentsRequest;
use App\Http\Resources\Agent\AgentDirectoryResource;
use App\Services\Delegation\DelegationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AgentDirectoryController extends Controller
{
    public function __construct(
        protected DelegationService $delegationService
    ) {}

    /**
     * Annuaire public et recherche des agents immobiliers certifiés (AG-06).
     * GET /api/agents/search
     */
    public function search(SearchAgentsRequest $request): AnonymousResourceCollection
    {
        $perPage = $request->integer('per_page', 15);

        $agents = $this->delegationService->searchAgents(
            $request->validated(),
            $perPage
        );

        return AgentDirectoryResource::collection($agents);
    }
}
