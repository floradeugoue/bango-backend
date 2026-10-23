<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Services\System\AppVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppVersionController extends Controller
{
    public function __construct(
        protected AppVersionService $versionService
    ) {}

    /**
     * Check minimum required and latest versions for client applications (SP / SY-21).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $platform = $request->query('platform')
            ?: $request->header('X-App-Platform')
            ?: 'android';

        $platform = strtolower(trim((string) $platform));

        if (! in_array($platform, ['android', 'ios', 'web'], true)) {
            return response()->json([
                'message' => 'La plateforme demandée est invalide. Valeurs acceptées : android, ios, web.',
                'errors' => [
                    'platform' => ['La plateforme doit être soit android, soit ios, soit web.'],
                ],
            ], 422);
        }

        $version = $request->query('version')
            ?: $request->header('X-App-Version');

        $result = $this->versionService->check($platform, $version ? (string) $version : null);

        return response()->json([
            'data' => $result,
        ], 200);
    }
}
