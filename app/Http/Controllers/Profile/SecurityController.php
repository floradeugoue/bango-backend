<?php
namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SecurityController extends Controller
{
    public function getSessions(Request $request)
    {
        $user = $request->user();
        
        // Mocking session list since Passport tokens represent sessions
        // In a real scenario, map $user->tokens to sessions.
        $sessions = $user->tokens->map(function($token) use ($request) {
            return [
                'id' => $token->id,
                'device' => $token->name ?? 'Unknown Device',
                'current' => $token->id === $request->user()->currentAccessToken()->id,
                'unusual' => false,
                'place' => 'Unknown',
                'last_used_at' => $token->updated_at,
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
        $currentTokenId = $user->currentAccessToken()->id;
        $signedOut = $user->tokens()->where('id', '!=', $currentTokenId)->delete();

        return response()->json([
            'signedOut' => $signedOut,
            'passwordPending' => true
        ]);
    }

    public function acknowledgeSession(Request $request, $id)
    {
        // Dummy implementation to mark session as acknowledged
        return response()->json(['success' => true]);
    }
}
