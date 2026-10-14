<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Http\Requests\Media\UploadMediaRequest;
use App\Services\Media\MediaUploadService;
use Illuminate\Http\JsonResponse;

class MediaUploadController extends Controller
{
    /**
     * Téléverse un média pour le carrousel Square (CR-02, CR-04, CR-12).
     */
    public function upload(UploadMediaRequest $request, MediaUploadService $service): JsonResponse
    {
        $file = $request->file('file');
        $user = $request->user();

        $result = $service->upload($file, $user);

        return response()->json([
            'success' => true,
            'data' => $result,
        ], 201);
    }
}
