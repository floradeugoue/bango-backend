<?php

namespace App\Http\Controllers\Square;

use App\Http\Controllers\Controller;
use App\Http\Resources\Square\PostMediaResource;
use App\Models\Square\PostMedia;
use App\Services\Media\MediaProcessingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PostMediaProcessingController extends Controller
{
    /**
     * Relance le traitement asynchrone d'une vidéo Square en échec.
     */
    public function retry(Request $request, string $id, MediaProcessingService $service): JsonResponse
    {
        $media = PostMedia::findOrFail($id);

        $service->retryPostMedia($media, $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Le traitement de la vidéo a été relancé.',
            'data' => new PostMediaResource($media->fresh()),
        ]);
    }

    /**
     * Consulte l'état de traitement d'un média Square.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $media = PostMedia::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => new PostMediaResource($media),
        ]);
    }
}
