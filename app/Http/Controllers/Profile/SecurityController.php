<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class SecurityController extends Controller
{
    public function getSessions(Request $request)
    {
        $user = $request->user();
        $currentTokenId = $request->user()->currentAccessToken()?->id;

        $place = $user->city ?? $user->country?->name ?? 'Douala, Cameroun';

        $sessions = $user->tokens->map(function ($token) use ($currentTokenId, $place) {
            $isAcknowledged = Cache::get('session.ack.'.$token->id, false);

            return [
                'id' => $token->id,
                'device' => (! empty($token->name) && $token->name !== 'auth_token') ? $token->name : 'Application mobile',
                'current' => $currentTokenId !== null && $token->id === $currentTokenId,
                'unusual' => ! $isAcknowledged && ($token->id !== $currentTokenId),
                'acknowledged' => (bool) $isAcknowledged,
                'place' => $place,
                'last_used_at' => $token->updated_at?->toIso8601String(),
            ];
        });

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

        return response()->json(['success' => true]);
    }
}
