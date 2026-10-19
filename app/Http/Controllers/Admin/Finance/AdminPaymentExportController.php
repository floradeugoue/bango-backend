<?php

namespace App\Http\Controllers\Admin\Finance;

use App\Http\Controllers\Controller;
use App\Services\Admin\Finance\AdminFinanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminPaymentExportController extends Controller
{
    public function __construct(
        protected AdminFinanceService $financeService
    ) {}

    /**
     * 4.18. POST /api/admin/payments/exports/preview
     * Préparer un aperçu d'export comptable sans mutation.
     */
    public function preview(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'provider', 'service_type', 'currency', 'from', 'to']);
        $preview = $this->financeService->previewExport($filters);

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    /**
     * 4.19. POST /api/admin/payments/exports
     * Produire un export comptable CSV signé et sécurisé.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['status', 'provider', 'service_type', 'currency', 'from', 'to']);

        return $this->financeService->exportTransactions($filters, $request->user());
    }
}
