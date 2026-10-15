<?php

namespace App\Http\Controllers\Listing;

use App\Enums\Listing\ListingPhotoRoom;
use App\Http\Controllers\Controller;
use App\Http\Requests\Listing\UploadListingMediaRequest;
use App\Http\Resources\Listing\ListingMediaResource;
use App\Models\Listing\Listing;
use App\Services\Listing\ListingService;
use App\Services\Media\MediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingMediaController extends Controller
{
    public function __construct(
        protected ListingService $listingService,
        protected MediaStorageService $mediaStorage
    ) {}

    /**
     * Téléversement et rattachement direct d'un média à une annonce existante.
     */
    public function store(UploadListingMediaRequest $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $file = $request->file('file');
        $type = $request->input('type');

        if ($type === 'image') {
            $room = $request->filled('room') ? ListingPhotoRoom::tryFrom($request->input('room')) : null;
            $media = $this->listingService->addPhoto($listing, $user, $file, $room);
        } else {
            $media = $this->listingService->addVideo(
                $listing,
                $user,
                $file,
                $request->integer('duration'),
                $request->integer('width'),
                $request->integer('height')
            );
        }

        return (new ListingMediaResource($media))->response()->setStatusCode(201);
    }

    /**
     * Téléversement autonome d'un média temporaire pour l'assistant de création (CR-42).
     */
    public function uploadTemp(UploadListingMediaRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $type = $request->input('type');

        if ($type === 'image') {
            $stored = $this->mediaStorage->storeListingPhoto($file, $user);
        } else {
            $stored = $this->mediaStorage->storeListingVideo(
                $file,
                $user,
                null,
                $request->integer('duration'),
                $request->integer('width'),
                $request->integer('height')
            );
        }

        return response()->json([
            'success' => true,
            'data' => $stored,
        ], 201);
    }

    /**
     * Réorganisation ordonnée des médias d'une annonce (CR-43).
     */
    public function reorder(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $request->validate([
            'media_ids' => ['required', 'array'],
            'media_ids.*' => ['string'],
        ]);

        $this->listingService->reorderMedia($listing, $user, $request->input('media_ids'));

        return response()->json([
            'success' => true,
            'message' => 'Ordre des médias mis à jour.',
        ]);
    }

    /**
     * Suppression d'un média rattaché à une annonce.
     */
    public function destroy(Request $request, string $id, string $mediaId): JsonResponse
    {
        $user = $request->user();
        /** @var Listing $listing */
        $listing = Listing::findOrFail($id);

        $this->listingService->removeMedia($listing, $user, $mediaId);

        return response()->json([
            'success' => true,
            'message' => 'Média supprimé avec succès.',
        ]);
    }
}
