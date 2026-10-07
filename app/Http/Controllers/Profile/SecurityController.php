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

        // 2. Enable 2FA if disabled
        if (!$user->two_factor_method) {
            $user->two_factor_method = 'sms';
        }

        // 3. Generate backup codes
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $codes[] = strtoupper(Str::random(10));
        }
        $user->backup_codes = $codes;

        $user->save();

        return response()->json([
            'signedOut' => $signedOut,
            'twoFactorEnabled' => true,
            'backupCodes' => $codes,
            'passwordPending' => true
        ]);
    }

    public function acknowledgeSession(Request $request, $id)
    {
        // Dummy implementation to mark session as acknowledged
        return response()->json(['success' => true]);
    }
}
