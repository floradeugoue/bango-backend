<?php

namespace App\Http\Controllers\Boost;

use App\Http\Controllers\Controller;
use App\Http\Requests\Boost\StoreCampaignRequest;
use App\Http\Resources\Boost\CampaignResource;
use App\Models\Boost\Campaign;
use App\Models\Page\Page;
use App\Services\Boost\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CampaignController extends Controller
{
    public function __construct(
        protected CampaignService $campaignService
    ) {}

    /**
     * Liste paginée des campagnes de l'utilisateur authentifié.
     *
     * GET /api/boost/campaigns
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $campaigns = Campaign::query()
            ->where('user_id', $request->user()->id)
            ->with(['metrics' => fn ($q) => $q->orderByDesc('metric_date')->take(7)])
            ->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 15));

        return CampaignResource::collection($campaigns);
    }

    /**
     * Création et initialisation d'une nouvelle campagne.
     *
     * POST /api/boost/campaigns
     */
    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $page = ! empty($validated['page_id']) ? Page::find($validated['page_id']) : null;
        $content = null;
        if (! empty($validated['boostable_type']) && ! empty($validated['boostable_id'])) {
            $resolvedClass = Campaign::resolveBoostableClass($validated['boostable_type']);
            if ($resolvedClass) {
                $content = $resolvedClass::find($validated['boostable_id']);
            }
        }

        Gate::authorize('boost.start', [$content, $page]);

        $campaign = $this->campaignService->createCampaign(
            user: $request->user(),
            data: $validated
        );

        return (new CampaignResource($campaign))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Détails complets et métriques d'une campagne.
     *
     * GET /api/boost/campaigns/{id}
     */
    public function show(string $id, Request $request): CampaignResource
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()
            ->with([
                'metrics' => fn ($q) => $q->orderBy('metric_date'),
                'results',
            ])
            ->findOrFail($id);

        Gate::authorize('view', $campaign);

        return new CampaignResource($campaign);
    }

    /**
     * Financement par Crédit BANGO d'une campagne en attente.
     *
     * POST /api/boost/campaigns/{id}/fund
     */
    public function fund(string $id, Request $request): CampaignResource
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        Gate::authorize('update', $campaign);
        Gate::authorize('boost.start', [$campaign->boostable, $campaign->page]);

        $funded = $this->campaignService->fundCampaignWithCredits(
            campaign: $campaign,
            user: $request->user(),
            idempotencyKey: $request->input('idempotency_key')
        );

        return new CampaignResource($funded);
    }

    /**
     * Augmente le budget d'une campagne en cours ou active (BO-07).
     *
     * POST /api/boost/campaigns/{id}/top-up
     */
    public function topUp(string $id, Request $request): CampaignResource
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        Gate::authorize('update', $campaign);

        $toppedUp = $this->campaignService->topUpCampaign(
            campaign: $campaign,
            user: $request->user(),
            amount: (float) $validated['amount'],
            idempotencyKey: $validated['idempotency_key'] ?? null
        );

        return new CampaignResource($toppedUp);
    }

    /**
     * Mise en pause d'une campagne active.
     *
     * POST /api/boost/campaigns/{id}/pause
     */
    public function pause(string $id, Request $request): CampaignResource
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        Gate::authorize('update', $campaign);

        $paused = $this->campaignService->pauseCampaign($campaign, $request->user());

        return new CampaignResource($paused);
    }

    /**
     * Reprise d'une campagne en pause.
     *
     * POST /api/boost/campaigns/{id}/resume
     */
    public function resume(string $id, Request $request): CampaignResource
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        Gate::authorize('update', $campaign);
        Gate::authorize('boost.start', [$campaign->boostable, $campaign->page]);

        $resumed = $this->campaignService->resumeCampaign($campaign, $request->user());

        return new CampaignResource($resumed);
    }

    /**
     * Interruption / annulation d'une campagne et restitution du reliquat en crédits.
     *
     * POST /api/boost/campaigns/{id}/cancel
     */
    public function cancel(string $id, Request $request): CampaignResource
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        Gate::authorize('update', $campaign);

        $cancelled = $this->campaignService->cancelCampaign(
            campaign: $campaign,
            user: $request->user(),
            reason: $request->input('reason', 'manual')
        );

        return new CampaignResource($cancelled);
    }

    /**
     * Enregistre une impression unitaire pour une campagne active.
     *
     * POST /api/boost/campaigns/{id}/impression
     */
    public function trackImpression(string $id, Request $request): JsonResponse
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        if (! $campaign->canBeServed()) {
            return response()->json([
                'message' => 'Cette campagne n\'est pas en cours de diffusion active.',
            ], 422);
        }

        $user = $request->user();

        $this->campaignService->recordDailyMetric(
            campaign: $campaign,
            date: now()->toDateString(),
            impressions: 1,
            clicks: 0,
            reach: 1,
            spent: 0.00,
            user: $user
        );

        $fresh = $campaign->fresh();

        return response()->json([
            'message' => 'Impression enregistrée.',
            'campaign_id' => $campaign->id,
            'impressions_count' => (int) $fresh->impressions_count,
            'reach_count' => (int) $fresh->reach_count,
            'spent_amount' => (float) $fresh->spent_amount,
        ]);
    }

    /**
     * Enregistre un clic unitaire pour une campagne active.
     *
     * POST /api/boost/campaigns/{id}/click
     */
    public function trackClick(string $id, Request $request): JsonResponse
    {
        /** @var Campaign $campaign */
        $campaign = Campaign::query()->findOrFail($id);

        if (! $campaign->canBeServed()) {
            return response()->json([
                'message' => 'Cette campagne n\'est pas en cours de diffusion active.',
            ], 422);
        }

        $user = $request->user();

        $this->campaignService->recordDailyMetric(
            campaign: $campaign,
            date: now()->toDateString(),
            impressions: 0,
            clicks: 1,
            reach: 0,
            spent: 0.00,
            user: $user
        );

        return response()->json([
            'message' => 'Clic enregistré.',
            'campaign_id' => $campaign->id,
            'clicks_count' => (int) $campaign->fresh()->clicks_count,
        ]);
    }
}
