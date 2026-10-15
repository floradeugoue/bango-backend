<?php

namespace App\Http\Controllers\Listing;

use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\SaveListingDraftRequest;
use App\Http\Resources\Listing\ListingDetailResource;
use App\Models\Listing\Listing;
use App\Services\Listing\ListingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingDraftController extends Controller
{
    public function __construct(
        protected ListingService $listingService
    ) {}

    /**
     * Consulter ou reprendre le brouillon d'annonce actif.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = $request->query('key') ?? $request->query('submission_key');

        $draft = $this->listingService->getDraft($user, $key);

        if (! $draft) {
            return response()->json([
                'success' => true,
                'data' => null,
            ]);
        }

        return (new ListingDetailResource($draft))->response();
    }

    /**
     * Enregistrer ou mettre à jour un brouillon d'annonce (CR-40 à CR-48).
     */
    public function store(SaveListingDraftRequest $request): JsonResponse
    {
        $user = $request->user();
        $key = $request->input('submission_key') ?? $request->input('key');

        $draft = $this->listingService->saveDraft($user, $request->validated(), $key);

        return (new ListingDetailResource($draft))->response()->setStatusCode(200);
    }

    /**
     * Abandonner et effacer un brouillon d'annonce.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        $key = $request->input('submission_key') ?? $request->input('key') ?? $request->query('key');

        if ($key) {
            Listing::where('owner_id', $user->id)
                ->where('submission_key', $key)
                ->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Brouillon supprimé avec succès.',
        ]);
    }
}
