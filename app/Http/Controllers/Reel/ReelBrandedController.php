<?php

namespace App\Http\Controllers\Reel;

use App\Http\Controllers\Controller;
use App\Services\Reel\ReelBrandingService;
use Illuminate\Http\JsonResponse;

class ReelBrandedController extends Controller
{
    public function __construct(
        protected ReelBrandingService $brandingService
    ) {}

    /**
     * Fournit la version marquée d'une vidéo (Point 1).
     * 200 { "url": "…/branded.mp4", "ready": true }
     * 202 { "ready": false } pendant la fabrication
     * 404 si le créateur ou le reel interdit le téléchargement
     */
    public function show(string $id): JsonResponse
    {
        return $this->brandingService->serveBrandedVideo($id);
    }
}
