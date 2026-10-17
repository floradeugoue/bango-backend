<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\StoreModerationRuleRequest;
use App\Http\Requests\Admin\Moderation\UpdateModerationRuleRequest;
use App\Services\Admin\ModerationRuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModerationRuleController extends Controller
{
    public function __construct(
        protected ModerationRuleService $ruleService
    ) {}

    /**
     * Liste des règles de modération (GET /moderation/rules).
     */
    public function index(Request $request): JsonResponse
    {
        $activeOnly = $request->has('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN) : null;
        $rules = $this->ruleService->getRules($activeOnly);

        return response()->json([
            'success' => true,
            'data' => $rules,
        ]);
    }

    /**
     * Création d'une nouvelle règle de modération (POST /moderation/rules).
     */
    public function store(StoreModerationRuleRequest $request): JsonResponse
    {
        $operator = $request->user();
        $rule = $this->ruleService->createRule($request->validated(), $operator);

        return response()->json([
            'success' => true,
            'message' => "Règle de modération '{$rule->code}' créée avec succès.",
            'data' => $rule,
        ], 201);
    }

    /**
     * Mise à jour d'une règle de modération existante (PATCH /moderation/rules/{reason}).
     */
    public function update(UpdateModerationRuleRequest $request, string $reason): JsonResponse
    {
        $operator = $request->user();
        $rule = $this->ruleService->updateRule($reason, $request->validated(), $operator);

        return response()->json([
            'success' => true,
            'message' => "Règle de modération '{$rule->code}' mise à jour avec succès.",
            'data' => $rule,
        ]);
    }

    /**
     * Correspondance entre les 6 motifs d'application et les motifs internes (GET /moderation/app-reasons).
     */
    public function appReasons(): JsonResponse
    {
        $mapping = $this->ruleService->getAppReasonsMapping();

        return response()->json([
            'success' => true,
            'data' => $mapping,
        ]);
    }
}
