<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sync\SyncFlushRequest;
use App\Services\Sync\OfflineSyncService;
use Illuminate\Http\JsonResponse;

class OfflineSyncController extends Controller
{
    public function __construct(protected OfflineSyncService $syncService) {}

    /**
     * Traite un lot d'actions effectuées hors-ligne par le client mobile.
     *
     * POST /api/sync/flush
     */
    public function flush(SyncFlushRequest $request): JsonResponse
    {
        $user = $request->user();
        $actions = $request->validated('actions');

        $result = $this->syncService->flush($user, $actions, $request->ip());

        return response()->json($result, 200);
    }
}
