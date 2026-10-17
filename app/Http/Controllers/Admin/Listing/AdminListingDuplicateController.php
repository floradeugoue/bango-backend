<?php

namespace App\Http\Controllers\Admin\Listing;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminListingControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminListingDuplicateController extends Controller
{
    public function __construct(
        protected AdminListingControlService $listingService
    ) {}

    /**
     * B.2. File des doublons potentiels d'annonces immobilières.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);
        $result = $this->listingService->getDuplicates($perPage);

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'total' => $result['total'],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        return $this->duplicates($request);
    }

    /**
     * B.3. Fusion transactionnelle de deux doublons.
     */
    public function merge(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'primary_ref' => ['required', 'string', 'exists:listings,id'],
            'secondary_ref' => ['required', 'string', 'exists:listings,id', 'different:primary_ref'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $primary = $this->listingService->mergeDuplicates(
                primaryId: $validated['primary_ref'],
                secondaryId: $validated['secondary_ref'],
                operator: $request->user(),
                reason: $validated['reason'] ?? null
            );
        } catch (\DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'code' => 'MERGE_INVALID',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Annonces fusionnées avec succès.',
            'data' => [
                'primary_id' => $primary->id,
                'status' => $primary->status->value,
            ],
        ]);
    }

    /**
     * B.3 bis. Rejet d'un doublon faux positif.
     */
    public function notDuplicate(Request $request, string $ref): JsonResponse
    {
        $validated = $request->validate([
            'comparison_ref' => ['required', 'string', 'exists:listings,id', 'different:ref'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->listingService->dismissDuplicate(
            ref1: $ref,
            ref2: $validated['comparison_ref'],
            operator: $request->user(),
            reason: $validated['reason'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Proposition de doublon écartée avec succès.',
        ]);
    }

    public function dismiss(Request $request, string $ref): JsonResponse
    {
        return $this->notDuplicate($request, $ref);
    }
}
