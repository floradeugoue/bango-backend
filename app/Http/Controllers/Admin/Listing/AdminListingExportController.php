<?php

namespace App\Http\Controllers\Admin\Listing;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminListingExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminListingExportController extends Controller
{
    public function __construct(
        protected AdminListingExportService $exportService
    ) {}

    /**
     * B.10. Aperçu du nombre d'annonces et colonnes exportables.
     */
    public function preview(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'city', 'kind']);
        $previewData = $this->exportService->preview($filters);

        return response()->json([
            'success' => true,
            'data' => $previewData,
        ]);
    }

    /**
     * B.10 bis. Génération et téléchargement sécurisé du fichier CSV.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $request->only(['status', 'city', 'kind']);

        return $this->exportService->export($filters, $request->user());
    }
}
