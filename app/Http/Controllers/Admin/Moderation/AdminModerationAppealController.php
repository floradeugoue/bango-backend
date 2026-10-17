<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ResolveAppealRequest;
use App\Services\Admin\ModerationAppealAdminService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminModerationAppealController extends Controller
{
    public function __construct(
        protected ModerationAppealAdminService $appealService
    ) {}

    /**
     * File paginée des recours ouverts avec filtres (GET /moderation/appeals).
     */
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'sanction_type' => $request->query('sanction_type'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'due_soon' => filter_var($request->query('due_soon', false), FILTER_VALIDATE_BOOLEAN),
        ];

        $perPage = (int) $request->query('per_page', 20);
        $appeals = $this->appealService->getAppealsQueue($filters, $perPage);

        return response()->json([
            'success' => true,
            'data' => $appeals->items(),
            'pagination' => [
                'current_page' => $appeals->currentPage(),
                'per_page' => $appeals->perPage(),
                'total' => $appeals->total(),
                'last_page' => $appeals->lastPage(),
            ],
        ]);
    }

    /**
     * Résolution d'un recours avec décision explicite (POST /moderation/appeals/{ref}/resolution).
     */
    public function resolve(ResolveAppealRequest $request, string $ref): JsonResponse
    {
        $operator = $request->user();

        try {
            $appeal = $this->appealService->resolveAppeal(
                appealRef: $ref,
                resolution: $request->input('resolution'),
                decisionNotes: $request->input('decision_notes'),
                operator: $operator
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'appeal' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Le recours {$appeal->reference} a été résolu avec succès ({$appeal->resolution}).",
            'data' => [
                'id' => $appeal->id,
                'reference' => $appeal->reference,
                'status' => $appeal->status->value,
                'resolution' => $appeal->resolution,
                'decision_notes' => $appeal->decision_notes,
                'reviewed_by' => $appeal->reviewed_by,
                'resolved_at' => $appeal->resolved_at?->toIso8601String(),
            ],
        ]);
    }
}
