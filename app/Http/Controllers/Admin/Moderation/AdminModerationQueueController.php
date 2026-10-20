<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ApplyDecisionRequest;
use App\Http\Requests\Admin\Moderation\AssignCasesRequest;
use App\Http\Requests\Admin\Moderation\BulkDecisionRequest;
use App\Http\Requests\Admin\Moderation\EscalateCaseRequest;
use App\Models\Support\Report;
use App\Services\Admin\ModerationQueueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminModerationQueueController extends Controller
{
    public function __construct(
        protected ModerationQueueService $queueService
    ) {}

    /**
     * File paginée des dossiers de modération avec filtres et compteurs (GET /moderation/queue).
     */
    public function queue(Request $request): JsonResponse
    {
        $filters = [
            'tab' => $request->query('tab', 'all'),
            'reason' => $request->query('reason'),
            'status' => $request->query('status'),
            'priority' => $request->query('priority'),
            'assigned_to' => $request->query('assigned_to'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'search' => $request->query('search'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
        ];

        $perPage = (int) $request->query('per_page', 20);
        $result = $this->queueService->getQueue($filters, $perPage);

        return response()->json([
            // Contract format: Page<T>
            'items' => $result['reports']->items(),
            'page' => $result['reports']->currentPage(),
            'perPage' => $result['reports']->perPage(),
            'total' => $result['reports']->total(),
            'pageCount' => $result['reports']->lastPage(),

            // Legacy keys for backward compatibility
            'success' => true,
            'data' => $result['reports']->items(),
            'pagination' => [
                'current_page' => $result['reports']->currentPage(),
                'per_page' => $result['reports']->perPage(),
                'total' => $result['reports']->total(),
                'last_page' => $result['reports']->lastPage(),
            ],
            'counters' => $result['counters'],
        ]);
    }

    /**
     * Consultation détaillée d'un dossier de modération (GET /moderation/cases/{ref}).
     */
    public function show(string $ref): JsonResponse
    {
        $caseData = $this->queueService->getCaseDetail($ref);

        return response()->json([
            'success' => true,
            'data' => $caseData,
        ]);
    }

    /**
     * Prise de décision validée sur un dossier (POST /moderation/cases/{ref}/decision).
     */
    public function decision(ApplyDecisionRequest $request, string $ref): JsonResponse
    {
        $report = Report::where('reference', $ref)
            ->orWhere('id', is_numeric($ref) ? (int) $ref : 0)
            ->firstOrFail();

        $validated = $request->validated();
        $operator = $request->attributes->get('admin_operator') ?? $request->user();

        try {
            $decision = $this->queueService->applyDecision(
                report: $report,
                action: $validated['action'],
                justification: $validated['justification'],
                operator: $operator,
                metadata: $validated['metadata'] ?? ['refund_credits' => $validated['refund_credits'] ?? false]
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'case' => [$e->getMessage()],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Décision de modération appliquée avec succès.',
            'data' => $decision->load('operator:id,name,email'),
        ]);
    }

    /**
     * Traitement groupé de dossiers de modération (POST /moderation/cases/bulk-decision).
     */
    public function bulkDecision(BulkDecisionRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $operator = $request->attributes->get('admin_operator') ?? $request->user();

        $result = $this->queueService->applyBulkDecisions(
            caseRefs: $validated['case_refs'],
            action: $validated['action'],
            justification: $validated['justification'],
            operator: $operator
        );

        return response()->json([
            'success' => true,
            'message' => 'Traitement groupé exécuté.',
            'data' => $result,
            'summary' => [
                'total' => count($validated['case_refs']),
                'succeeded' => count($result['processed']),
                'skipped' => count($result['skipped']),
                'failed' => count($result['errors']),
            ],
        ]);
    }

    /**
     * Liste des opérateurs disponibles pour assignation (GET /moderation/assignees).
     */
    public function assignees(): JsonResponse
    {
        $operators = $this->queueService->getAssignees();

        return response()->json([
            'success' => true,
            'data' => $operators,
        ]);
    }

    /**
     * Assignation de dossiers à un opérateur (POST /moderation/cases/assign).
     */
    public function assign(AssignCasesRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $currentOperator = $request->attributes->get('admin_operator') ?? $request->user();

        $assignedRefs = $this->queueService->assignCases(
            caseRefs: $validated['case_refs'],
            operatorId: $validated['operator_id'],
            currentOperator: $currentOperator
        );

        return response()->json([
            'success' => true,
            'message' => count($assignedRefs).' dossier(s) assigné(s) avec succès.',
            'assigned_cases' => $assignedRefs,
            'assigned_count' => count($assignedRefs),
        ]);
    }

    /**
     * Escalade d'un dossier vers un responsable (POST /moderation/cases/{ref}/escalate).
     */
    public function escalate(EscalateCaseRequest $request, string $ref): JsonResponse
    {
        $report = Report::where('reference', $ref)
            ->orWhere('id', is_numeric($ref) ? (int) $ref : 0)
            ->firstOrFail();

        $validated = $request->validated();
        $currentOperator = $request->attributes->get('admin_operator') ?? $request->user();

        $escalated = $this->queueService->escalateCase(
            report: $report,
            seniorOperatorId: $validated['senior_operator_id'],
            reason: $validated['reason'],
            currentOperator: $currentOperator
        );

        return response()->json([
            'success' => true,
            'message' => 'Dossier escaladé avec succès.',
            'data' => $escalated->load('escalatedOperator:id,name,email'),
        ]);
    }

    /**
     * Historique des décisions prises par l'opérateur connecté (GET /moderation/decisions/mine).
     */
    public function myDecisions(Request $request): JsonResponse
    {
        $operator = $request->attributes->get('admin_operator') ?? $request->user();
        $perPage = (int) $request->query('per_page', 20);

        $decisions = $this->queueService->getMyDecisions($operator, $perPage);

        return response()->json([
            // Contract format: Page<T>
            'items' => $decisions->items(),
            'page' => $decisions->currentPage(),
            'perPage' => $decisions->perPage(),
            'total' => $decisions->total(),
            'pageCount' => $decisions->lastPage(),

            // Legacy keys for backward compatibility
            'success' => true,
            'data' => $decisions->items(),
            'pagination' => [
                'current_page' => $decisions->currentPage(),
                'per_page' => $decisions->perPage(),
                'total' => $decisions->total(),
                'last_page' => $decisions->lastPage(),
            ],
        ]);
    }

    /**
     * Export sécurisé de l'activité de modération de l'opérateur (POST /moderation/mine/export).
     */
    public function export(Request $request): JsonResponse
    {
        $operator = $request->attributes->get('admin_operator') ?? $request->user();
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        $exportData = $this->queueService->exportMyActivity($operator, $from, $to);

        return response()->json([
            'success' => true,
            'message' => 'Export d\'activité généré avec succès.',
            'data' => $exportData,
        ]);
    }
}
