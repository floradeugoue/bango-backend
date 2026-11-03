<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Http\Requests\Landlord\SubmitKycRequest;
use App\Http\Resources\Kyc\KycDocumentResource;
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
     * Remplacement ou complément d'une pièce sur le dossier KYC en attente de complément.
     * POST /api/landlord/kyc/supplement
     */
    public function submitSupplement(SubmitKycRequest $request): JsonResponse
    {
        $user = $request->user();
        $files = $request->allFiles();

        $submission = $this->kycService->submitSupplement(
            $user,
            $files,
            $request->validated(),
            $request->input('submission_id')
        );

        return (new KycSubmissionResource($submission))
            ->response()
            ->setStatusCode(200);
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

    /**
     * Ajouter un justificatif de propriété supplémentaire pour un bien.
     * POST /api/landlord/kyc/property-deeds
     */
    public function storePropertyDeed(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'property_deed' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'deed' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'file' => ['nullable', 'file', 'max:10240', 'mimes:jpeg,jpg,png,webp,pdf'],
            'deed_type' => ['nullable', 'string', 'max:64'],
            'listing_id' => ['nullable', 'uuid'],
        ]);

        $file = $request->file('property_deed') ?? $request->file('deed') ?? $request->file('file');

        if (! $file) {
            return response()->json([
                'message' => 'Un fichier de justificatif de propriété est requis.',
                'errors' => [
                    'property_deed' => ['Le fichier de titre de propriété est manquant.'],
                ],
            ], 422);
        }

        $document = $this->kycService->addPropertyDeed(
            user: $request->user(),
            file: $file,
            deedType: $validated['deed_type'] ?? null,
            listingId: $validated['listing_id'] ?? null
        );

        return response()->json([
            'message' => 'Justificatif de propriété ajouté avec succès. Il sera examiné par la conformité.',
            'data' => new KycDocumentResource($document),
        ], 201);
    }

    /**
     * Lister les justificatifs de propriété du bailleur avec leur état.
     * GET /api/landlord/kyc/property-deeds
     */
    public function listPropertyDeeds(Request $request): JsonResponse
    {
        $deeds = $this->kycService->listPropertyDeeds($request->user());

        return response()->json([
            'data' => KycDocumentResource::collection($deeds),
        ]);
    }
}
