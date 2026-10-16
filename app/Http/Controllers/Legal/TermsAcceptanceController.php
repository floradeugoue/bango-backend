<?php

namespace App\Http\Controllers\Legal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Legal\AcceptTermsRequest;
use App\Services\Legal\LegalDocumentService;
use Illuminate\Http\JsonResponse;

class TermsAcceptanceController extends Controller
{
    public function __construct(
        protected LegalDocumentService $legalDocumentService
    ) {}

    /**
     * Enregistre l'acceptation des CGU par l'utilisateur connecté.
     */
    public function store(AcceptTermsRequest $request): JsonResponse
    {
        $user = $request->user();
        $documentSlug = $request->input('document_slug', LegalDocumentService::TERMS_SLUG);
        $providedVersion = $request->input('version');
        $locale = $request->input('locale', LegalDocumentService::DEFAULT_LOCALE);

        $mandatoryVersion = $this->legalDocumentService->getMandatoryTermsVersion();

        // Si le client a envoyé explicitement une version obsolète différente de la version obligatoire
        if ($providedVersion !== null && $providedVersion !== $mandatoryVersion) {
            return response()->json([
                'message' => 'Une nouvelle version des conditions générales d\'utilisation est requise.',
                'error' => 'OUTDATED_TERMS_VERSION',
                'required_version' => $mandatoryVersion,
                'provided_version' => $providedVersion,
                'document_slug' => $documentSlug,
            ], 422);
        }

        $acceptance = $this->legalDocumentService->recordAcceptance(
            user: $user,
            documentSlug: $documentSlug,
            version: $mandatoryVersion,
            locale: $locale,
            ip: $request->ip(),
            userAgent: $request->userAgent()
        );

        return response()->json([
            'success' => true,
            'message' => 'Conditions générales d\'utilisation acceptées avec succès.',
            'version' => $acceptance->version,
            'document_slug' => $acceptance->document_slug,
            'accepted_at' => $acceptance->accepted_at?->toIso8601String(),
            'is_compliant' => true,
        ]);
    }
}
