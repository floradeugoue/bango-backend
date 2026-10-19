<?php

namespace App\Http\Controllers\Admin\Audit;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Audit\AdminAuditEditAttemptRequest;
use App\Models\Admin\AdminAuditExport;
use App\Services\Admin\Audit\AdminAuditChainService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminAuditController extends Controller
{
    public function __construct(
        protected AdminAuditChainService $auditService
    ) {}

    /**
     * 4.1. Consultation paginée du registre d'audit.
     *
     * GET /api/admin/audit
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['operator_id', 'event', 'target_type', 'search', 'date_from', 'date_to', 'sort_by', 'sort_order']);
        $perPage = (int) $request->input('per_page', 20);

        $logs = $this->auditService->getLogs($filters, $perPage);
        $chainStatus = $this->auditService->verifyChain(100);

        return response()->json([
            'success' => true,
            'chain_status' => $chainStatus,
            'data' => $logs->items(),
            'pagination' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * 4.1 bis. Détail d'un événement d'audit spécifique avec empreinte et vérification.
     *
     * GET /api/admin/audit/{id}
     */
    public function show(int|string $id): JsonResponse
    {
        $logData = $this->auditService->getLog($id);

        return response()->json([
            'success' => true,
            'data' => $logData,
        ]);
    }

    /**
     * 4.2 bis. Vérification globale de la chaîne cryptographique SHA-256.
     *
     * GET /api/admin/audit/verify
     */
    public function verify(): JsonResponse
    {
        $status = $this->auditService->verifyChain();

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * 4.3. Enregistrement d'une tentative de modification illicite.
     * L'événement cible N'EST JAMAIS MODIFIÉ.
     *
     * POST /api/admin/audit/{id}/edit-attempt
     */
    public function editAttempt(AdminAuditEditAttemptRequest $request, int|string $id): JsonResponse
    {
        $attempt = $this->auditService->recordTamperAttempt(
            auditId: $id,
            operator: $request->user(),
            attemptedChanges: $request->input('attempted_changes', []),
            reason: $request->input('reason'),
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Tentative d\'altération consignée avec succès dans les alertes de sécurité.',
            'data' => $attempt,
        ], 201);
    }

    /**
     * 4.6. Liste des exports d'audit archivés.
     *
     * GET /api/admin/audit/exports
     */
    public function exports(): JsonResponse
    {
        $exports = AdminAuditExport::with('requester:id,name,email')
            ->orderBy('id', 'desc')
            ->paginate(20);

        return response()->json([
            'success' => true,
            'data' => $exports->items(),
            'pagination' => [
                'current_page' => $exports->currentPage(),
                'total' => $exports->total(),
            ],
        ]);
    }

    /**
     * 4.6 bis. Prévisualisation du périmètre d'un export d'audit.
     *
     * POST /api/admin/audit/exports/preview
     */
    public function previewExport(Request $request): JsonResponse
    {
        $filters = $request->only(['event', 'operator_id', 'date_from', 'date_to']);
        $preview = $this->auditService->previewExport($filters);

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 4.6 ter. Génération et téléchargement d'un export d'audit scellé SHA-256.
     *
     * POST /api/admin/audit/exports
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['event', 'operator_id', 'date_from', 'date_to']);

        return $this->auditService->exportAuditCsv($filters, $request->user());
    }

    /**
     * 4.6 quater. Vérification d'intégrité d'un export scellé par son empreinte.
     *
     * GET /api/admin/audit/exports/verify
     */
    public function verifyExport(Request $request): JsonResponse
    {
        $seal = (string) $request->input('seal');
        if (empty($seal)) {
            return response()->json([
                'success' => false,
                'message' => 'Le paramètre seal (empreinte SHA-256) est requis.',
            ], 422);
        }

        $result = $this->auditService->verifyExportSeal($seal);

        return response()->json([
            'success' => $result['is_authentic'],
            'data' => $result,
        ]);
    }
}
