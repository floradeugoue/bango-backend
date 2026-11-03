<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Services\Auth\SessionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityController extends Controller
{
    public function __construct(
        protected SessionService $sessionService
    ) {}

    public function getSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $user->currentAccessToken()?->id;

        $sessions = $this->sessionService->getFormattedSessions($user, $currentTokenId, $request);

        return response()->json([
            'sessions' => $sessions,
            'twoFactor' => $user->two_factor_method ?? 'off',
            'backupCodesCount' => $user->backup_codes ? count($user->backup_codes) : 0,
        ]);
    }

    public function secureAccount(Request $request)
    {
        $user = $request->user();

        // 1. Revoke all tokens except current
        $currentTokenId = $user->currentAccessToken()?->id;
        $signedOut = $user->tokens()->when($currentTokenId, function ($query, $id) {
            $query->where('id', '!=', $id);
        })->delete();

        return response()->json([
            'signedOut' => $signedOut,
            'passwordPending' => true,
        ]);
    }

    public function acknowledgeSession(Request $request, $id)
    {
        Cache::put('session.ack.'.$id, true, now()->addDays(90));

        return response()->json(['success' => true]);
    }

    public function revokeSession(Request $request, $id)
    {
        $user = $request->user();
        $token = $user->tokens()->where('id', $id)->first();

        if (! $token) {
            return response()->json(['message' => 'Session not found'], 404);
        }

        $token->delete();
        Cache::forget('session.ack.'.$id);
        Cache::forget('session.meta.'.$id);

        return response()->json(['success' => true]);
    }
}
