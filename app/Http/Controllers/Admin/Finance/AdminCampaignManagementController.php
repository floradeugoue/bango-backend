<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Finance\CampaignNotifyRequest;
use App\Http\Requests\Admin\Finance\CampaignSuspendRequest;
use App\Models\Boost\Campaign;
use App\Services\Admin\Finance\AdminBoostManagementService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminCampaignManagementController extends Controller
{
    public function __construct(
        protected AdminBoostManagementService $boostService
    ) {}

    /**
     * 5.1. GET /api/admin/campaigns
     * Fournir une liste paginée des campagnes de Boost avec métriques et filtres.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'user_id', 'boostable_type', 'from', 'to', 'q']);
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->boostService->getCampaigns($filters, $perPage);

        $items = collect($paginator->items())->map(function (Campaign $c) {
            $budget = (float) $c->budget_amount;
            $spent = (float) $c->spent_amount;
            $remaining = max(0.0, $budget - $spent);

            return [
                'id' => (string) $c->id,
                'advertiser' => $c->user ? [
                    'id' => $c->user->id,
                    'name' => $c->user->name,
                    'email' => $c->user->email,
                    'phone' => $c->user->phone,
                ] : null,
                'page' => $c->page ? [
                    'id' => $c->page->id,
                    'name' => $c->page->name,
                ] : null,
                'promoted_content' => [
                    'type' => class_basename($c->boostable_type),
                    'id' => (string) $c->boostable_id,
                ],
                'objective' => $c->objective->value,
                'status' => $c->status->value,
                'status_label' => $c->status->label(),
                'budget_amount' => $budget,
                'spent_amount' => $spent,
                'remaining_budget' => $remaining,
                'currency' => $c->budget_currency ?: 'XAF',
                'duration_days' => $c->duration_days,
                'impressions_count' => $c->impressions_count,
                'clicks_count' => $c->clicks_count,
                'reach_count' => $c->reach_count,
                'start_at' => $c->start_at?->toIso8601String(),
                'end_at' => $c->end_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
                'updated_at' => $c->updated_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 5.2. GET /api/admin/campaigns/{id}
     * Retourner le détail d'une campagne de Boost.
     */
    public function show(string $id): JsonResponse
    {
        $detail = $this->boostService->getCampaignDetail($id);

        if (! $detail) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CAMPAIGN_NOT_FOUND', 'message' => 'Campagne de Boost introuvable.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $detail,
            'message' => 'Détails de la campagne récupérés avec succès.',
        ]);
    }

    /**
     * 5.3. GET /api/admin/campaigns/refunds
     * Lister les restitutions de budget associées aux campagnes.
     */
    public function refunds(Request $request): JsonResponse
    {
        $filters = $request->only(['user_id', 'campaign_id', 'from', 'to']);
        $perPage = $request->integer('per_page', 20);

        $paginator = $this->boostService->getCampaignRefunds($filters, $perPage);

        $items = collect($paginator->items())->map(function ($tx) {
            return [
                'id' => $tx->id,
                'reference' => $tx->reference,
                'campaign_id' => $tx->reference_id,
                'advertiser' => $tx->user ? [
                    'id' => $tx->user->id,
                    'name' => $tx->user->name,
                    'email' => $tx->user->email,
                ] : null,
                'amount' => $tx->amount,
                'balance_after' => $tx->balance_after,
                'description' => $tx->description,
                'metadata' => $tx->metadata,
                'created_at' => $tx->created_at?->toIso8601String(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * 5.4. POST /api/admin/campaigns/suspend/preview
     * Calculer le montant éventuellement restituable avant suspension sans mutation.
     */
    public function previewSuspend(Request $request): JsonResponse
    {
        $campaignId = $request->input('campaign_id') ?? $request->input('id');
        if (! $campaignId) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CAMPAIGN_ID_REQUIRED', 'message' => 'Le paramètre campaign_id est obligatoire.'],
            ], 422);
        }

        /** @var Campaign|null $campaign */
        $campaign = Campaign::find($campaignId);
        if (! $campaign) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CAMPAIGN_NOT_FOUND', 'message' => 'Campagne introuvable.'],
            ], 404);
        }

        $preview = $this->boostService->previewSuspension($campaign);

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 5.5. POST /api/admin/campaigns/suspend
     * Suspendre une campagne et créditer automatiquement l'annonceur du montant non consommé.
     */
    public function suspend(CampaignSuspendRequest $request): JsonResponse
    {
        /** @var Campaign|null $campaign */
        $campaign = Campaign::find($request->input('campaign_id'));
        if (! $campaign) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CAMPAIGN_NOT_FOUND', 'message' => 'Campagne introuvable.'],
            ], 404);
        }

        try {
            $result = $this->boostService->suspendCampaignWithRestitution(
                campaign: $campaign,
                operator: $request->user(),
                reason: $request->input('reason')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'SUSPENSION_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Campagne suspendue avec succès et budget non consommé restitué à l\'annonceur.',
        ]);
    }

    /**
     * 5.6. POST /api/admin/campaigns/{id}/notify
     * Envoyer un message explicatif à l'annonceur dans sa langue.
     */
    public function notify(CampaignNotifyRequest $request, string $id): JsonResponse
    {
        /** @var Campaign|null $campaign */
        $campaign = Campaign::find($id);
        if (! $campaign) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'CAMPAIGN_NOT_FOUND', 'message' => 'Campagne introuvable.'],
            ], 404);
        }

        try {
            $result = $this->boostService->notifyAdvertiser(
                campaign: $campaign,
                operator: $request->user(),
                message: $request->input('message'),
                language: $request->input('language')
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'NOTIFICATION_FAILED', 'message' => $e->getMessage()],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'message' => 'Notification envoyée avec succès à l\'annonceur.',
        ]);
    }

    /**
     * 5.7. POST /api/admin/campaigns/exports
     * Produire un export CSV sécurisé des campagnes.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['status', 'user_id', 'from', 'to']);

        return $this->boostService->exportCampaigns($filters, $request->user());
    }
}
