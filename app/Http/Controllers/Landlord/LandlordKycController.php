<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\SubmitKycRequest;
use App\Http\Resources\Kyc\KycSubmissionResource;
use App\Models\Kyc\KycDocument;
use App\Services\Kyc\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LandlordKycController extends Controller
{
    public function __construct(
        protected KycService $kycService
    ) {}

    /**
     * Dépôt du dossier KYC de vérification d'identité (BL-02).
     * POST /api/landlord/kyc/submit
     */
    public function submit(SubmitKycRequest $request): JsonResponse
    {
        $user = $request->user();
        $files = $request->allFiles();

        $submission = $this->kycService->submitKyc(
            $user,
            $request->validated(),
            $files
        );

        return (new KycSubmissionResource($submission))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Consulter le statut KYC actuel de l'utilisateur.
     * GET /api/landlord/kyc
     */
    public function show(Request $request): JsonResponse
    {
        $status = $this->kycService->getKycStatus($request->user());

        return response()->json([
            'is_verified' => $status['is_verified'],
            'verification_lapsed' => $status['verification_lapsed'],
            'has_pending_submission' => $status['has_pending_submission'],
            'latest_submission' => $status['latest_submission'] ? new KycSubmissionResource($status['latest_submission']) : null,
        ]);
    }

    /**
     * Télécharger ou consulter un document KYC privé.
     * GET /api/landlord/kyc/documents/{id}
     */
    public function downloadDocument(Request $request, string $id): StreamedResponse
    {
        /** @var KycDocument $document */
        $document = KycDocument::findOrFail($id);

        return $this->kycService->downloadDocument($document, $request->user());
    }
}
