<?php

namespace App\Http\Controllers\Boost;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boost\EstimateCampaignRequest;
use App\Http\Resources\Boost\CampaignEstimateResource;
use App\Models\Page\Page;
use App\Services\Boost\CampaignEstimateService;
use App\Services\Boost\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class BoostEstimateController extends Controller
{
    /**
     * Calcule l'estimation de portée prévisionnelle pour une campagne.
     *
     * POST /api/boost/estimate
     */
    public function estimate(
        EstimateCampaignRequest $request,
        CampaignEstimateService $estimateService,
        CampaignService $campaignService
    ): JsonResponse {
        $validated = $request->validated();

        $page = ! empty($validated['page_id']) ? Page::find($validated['page_id']) : null;
        $content = null;

        // Si le contenu est spécifié, vérifier son existence et l'éligibilité de l'utilisateur
        if (! empty($validated['boostable_type']) && ! empty($validated['boostable_id'])) {
            $content = $campaignService->validateContentForPromotion(
                user: $request->user(),
                boostableType: $validated['boostable_type'],
                boostableId: $validated['boostable_id']
            );
        }

        if ($content !== null || $page !== null) {
            Gate::authorize('boost.start', [$content, $page]);
        }

        $estimate = $estimateService->estimate($validated);

        return (new CampaignEstimateResource($estimate))
            ->response()
            ->setStatusCode(200);
    }
}
