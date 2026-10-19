<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Settings\AdminAreaCreateRequest;
use App\Http\Requests\Admin\Settings\AdminAreaUpdateRequest;
use App\Http\Requests\Admin\Settings\AdminInterestRequest;
use App\Http\Requests\Admin\Settings\AdminLegalPublishRequest;
use App\Http\Requests\Admin\Settings\AdminOperatorSuspendRequest;
use App\Http\Requests\Admin\Settings\AdminPricingChangeRequest;
use App\Http\Requests\Admin\Settings\AdminServiceActionRequest;
use App\Http\Requests\Admin\Settings\AdminThresholdsUpdateRequest;
use App\Services\Admin\Settings\AdminSystemSettingsService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSystemSettingsController extends Controller
{
    public function __construct(
        protected AdminSystemSettingsService $settingsService
    ) {}

    // =========================================================================
    // 5.1. TARIFICATION
    // =========================================================================

    public function pricing(): JsonResponse
    {
        $catalog = $this->settingsService->getPricingCatalog();

        return response()->json([
            'success' => true,
            'data' => $catalog,
        ]);
    }

    public function pricingImpact(Request $request): JsonResponse
    {
        $priceId = (int) $request->input('price_id');
        $newAmount = (float) ($request->input('new_amount') ?? $request->input('proposed_amount') ?? $request->input('new_price_amount'));

        if (! $priceId || $newAmount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Les paramètres price_id et new_amount sont requis.',
            ], 422);
        }

        $impact = $this->settingsService->previewPricingImpact($priceId, $newAmount);

        return response()->json([
            'success' => true,
            'data' => $impact,
        ]);
    }

    public function proposePricingChange(AdminPricingChangeRequest $request): JsonResponse
    {
        $proposal = $this->settingsService->proposePricingChange(
            priceId: (int) $request->input('price_id'),
            newAmount: (float) $request->input('new_price_amount'),
            noticeDays: (int) ($request->input('notice_days') ?? 30),
            reason: $request->input('reason'),
            operator: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Modification tarifaire programmée avec succès.',
            'data' => $proposal,
        ], 201);
    }

    // =========================================================================
    // 5.2. SEUILS AUTOMATIQUES
    // =========================================================================

    public function thresholds(): JsonResponse
    {
        $data = $this->settingsService->getModerationThresholds();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function updateThresholds(AdminThresholdsUpdateRequest $request): JsonResponse
    {
        $thresholds = $request->input('thresholds');
        if (! is_array($thresholds)) {
            $thresholds = $request->except(['reason', '_token']);
        }

        $data = $this->settingsService->updateModerationThresholds(
            thresholds: $thresholds,
            operator: $request->user(),
            reason: $request->input('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Seuils automatiques de modération mis à jour avec succès.',
            'data' => $data,
        ]);
    }

    // =========================================================================
    // 5.3. OPÉRATEURS MOBILE MONEY
    // =========================================================================

    public function operators(): JsonResponse
    {
        $data = $this->settingsService->getMobileMoneyOperators();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function operatorsImpact(Request $request): JsonResponse
    {
        $operatorId = (int) $request->input('operator_id');
        if (! $operatorId) {
            return response()->json([
                'success' => false,
                'message' => 'Le paramètre operator_id est requis.',
            ], 422);
        }

        $impact = $this->settingsService->previewOperatorSuspension($operatorId);

        return response()->json([
            'success' => true,
            'data' => $impact,
        ]);
    }

    public function operatorImpact(Request $request): JsonResponse
    {
        $operatorId = (int) $request->input('operator_id');
        $preview = $this->settingsService->previewOperatorSuspension($operatorId);

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    public function suspendOperator(AdminOperatorSuspendRequest $request): JsonResponse
    {
        $operatorId = (int) ($request->input('operator_id') ?? $request->route('id'));
        try {
            $op = $this->settingsService->suspendOperator(
                operatorId: $operatorId,
                reason: $request->input('reason'),
                fallbackOperatorId: $request->input('fallback_operator_id') ? (int) $request->input('fallback_operator_id') : null,
                operator: $request->user()
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Opérateur {$op->name} suspendu avec succès.",
            'data' => $op,
        ]);
    }

    public function resumeOperator(Request $request): JsonResponse
    {
        $operatorId = (int) ($request->input('operator_id') ?? $request->route('id'));
        if (! $operatorId) {
            return response()->json(['success' => false, 'message' => 'Le paramètre operator_id est requis.'], 422);
        }

        try {
            $op = $this->settingsService->resumeOperator($operatorId, $request->user());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => "Opérateur {$op->name} réactivé avec succès.",
            'data' => $op,
        ]);
    }

    // =========================================================================
    // 5.4. RÉFÉRENTIEL GÉOGRAPHIQUE
    // =========================================================================

    public function cities(): JsonResponse
    {
        $data = $this->settingsService->getCities();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function areas(Request $request): JsonResponse
    {
        $city = $request->input('city');
        $status = $request->input('status');

        $data = $this->settingsService->getAreas($city, $status);

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function addArea(AdminAreaCreateRequest $request, string $city): JsonResponse
    {
        try {
            $area = $this->settingsService->addArea($city, $request->input('name'), $request->user());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quartier ajouté avec succès.',
            'data' => $area,
        ], 201);
    }

    public function createArea(AdminAreaCreateRequest $request, string $city): JsonResponse
    {
        return $this->addArea($request, $city);
    }

    public function updateArea(AdminAreaUpdateRequest $request, string $city, string $name): JsonResponse
    {
        try {
            $area = $this->settingsService->updateArea($city, $name, $request->validated(), $request->user());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Quartier mis à jour avec succès.',
            'data' => $area,
        ]);
    }

    public function ignoreArea(Request $request): JsonResponse
    {
        $city = (string) $request->input('city');
        $name = (string) $request->input('name');
        $ignore = filter_var($request->input('ignore', true), FILTER_VALIDATE_BOOLEAN);

        if (empty($city) || empty($name)) {
            return response()->json(['success' => false, 'message' => 'Les paramètres city et name sont requis.'], 422);
        }

        try {
            $area = $this->settingsService->ignoreArea($city, $name, $ignore, $request->user());
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $ignore ? 'Quartier ignoré des suggestions.' : 'Quartier restauré dans les suggestions.',
            'data' => $area,
        ]);
    }

    // =========================================================================
    // 5.5. CENTRES D'INTÉRÊT
    // =========================================================================

    public function interests(): JsonResponse
    {
        $data = $this->settingsService->getInterests();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function createInterest(AdminInterestRequest $request): JsonResponse
    {
        $interest = $this->settingsService->createInterest($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Centre d\'intérêt créé avec succès.',
            'data' => $interest,
        ], 201);
    }

    public function updateInterest(AdminInterestRequest $request, string $id): JsonResponse
    {
        $interest = $this->settingsService->updateInterest($id, $request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Centre d\'intérêt mis à jour avec succès.',
            'data' => $interest,
        ]);
    }

    // =========================================================================
    // 5.6. CGU ET VERSIONS LÉGALES
    // =========================================================================

    public function legal(): JsonResponse
    {
        $data = $this->settingsService->getLegalDocuments();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function legalDocuments(): JsonResponse
    {
        return $this->legal();
    }

    public function publishLegal(AdminLegalPublishRequest $request, string $key): JsonResponse
    {
        $doc = $this->settingsService->publishLegalDocument(
            key: $key,
            version: $request->input('version'),
            title: $request->input('title'),
            contentMarkdown: $request->input('content_markdown'),
            effectiveDate: $request->input('effective_date') ? Carbon::parse($request->input('effective_date')) : null,
            isMandatory: filter_var($request->input('is_mandatory', true), FILTER_VALIDATE_BOOLEAN),
            operator: $request->user(),
            locale: $request->input('locale', 'fr')
        );

        return response()->json([
            'success' => true,
            'message' => "Nouvelle version {$doc->version} publiée pour {$doc->slug}.",
            'data' => $doc,
        ], 201);
    }

    public function publishLegalDocument(AdminLegalPublishRequest $request, string $key): JsonResponse
    {
        return $this->publishLegal($request, $key);
    }

    // =========================================================================
    // 5.7. MAINTENANCE ET INCIDENTS
    // =========================================================================

    public function service(): JsonResponse
    {
        $data = $this->settingsService->getServiceStatus();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function serviceAction(AdminServiceActionRequest $request): JsonResponse
    {
        try {
            $data = $this->settingsService->executeServiceAction(
                action: $request->input('action'),
                params: $request->input('params', []),
                operator: $request->user()
            );
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Action de service exécutée avec succès.',
            'data' => $data,
        ]);
    }

    // =========================================================================
    // 5.8. RÉFÉRENTIEL DES DÉLAIS
    // =========================================================================

    public function delays(): JsonResponse
    {
        $data = $this->settingsService->getDelays();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
