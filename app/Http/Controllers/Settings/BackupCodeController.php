<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\BackupCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BackupCodeController extends Controller
{
    public function __construct(
        protected BackupCodeService $backupCodeService
    ) {}

    /**
     * Get the count and state of existing backup codes (never reveals plaintext codes).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $remaining = $this->backupCodeService->getRemainingCount($user);

        return response()->json([
            'has_backup_codes' => $remaining > 0,
            'count' => $remaining,
            'remaining' => $remaining,
        ], 200);
    }

    /**
     * Regenerate 8 fresh backup codes, immediately invalidating any prior codes (ST-10).
     */
    public function regenerate(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $this->backupCodeService->generateCodes();

        $user->backup_codes = $result['hashed'];
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Nouveaux codes de secours générés. Conservez-les en lieu sûr.',
            'backup_codes' => $result['plain'],
            'count' => 8,
            'remaining' => 8,
        ], 200);
    }
}
