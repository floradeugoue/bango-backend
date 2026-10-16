<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Http\Resources\Legal\LegalDocumentResource;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LegalDocumentController extends Controller
{
    public function __construct(
        protected LegalDocumentService $legalDocumentService
    ) {}

    /**
     * Consulte un document légal publié par son slug et sa langue.
     */
    public function show(Request $request, string $slug): LegalDocumentResource|JsonResponse
    {
        $locale = $request->query('locale');
        $version = $request->query('version');

        $document = $this->legalDocumentService->getPublishedDocument($slug, $locale, $version);

        if ($document === null) {
            return response()->json([
                'message' => 'Document légal introuvable ou non disponible.',
                'error' => 'DOCUMENT_NOT_FOUND',
                'slug' => $slug,
                'locale' => $locale ?: LegalDocumentService::DEFAULT_LOCALE,
            ], 404);
        }

        return new LegalDocumentResource($document);
    }
}
