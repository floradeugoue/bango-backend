<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Http\JsonResponse;

class SignupController extends Controller
{
    public function __invoke(SignupRequest $request): JsonResponse
    {
        // Custom password validation since FormRequest handles the error format
        $pwd = $request->password;
        $failedRules = [];
        if (strlen($pwd) < 8) $failedRules[] = 'min-length';
        if (!preg_match('/\d/', $pwd)) $failedRules[] = 'needs-digit';
        if (preg_match('/1234|abcd|qwerty|qwertz|azerty/i', $pwd)) $failedRules[] = 'no-sequence';
        
        if (!empty($failedRules)) {
            return response()->json([
                'kind' => 'weak-password',
                'failed' => $failedRules
            ], 422);
        }

        $user = clone new User();
        $user->name = $request->name ?? explode('@', $request->email)[0];
        $user->email = $request->email;
        $user->password = Hash::make($request->password);
        
        $userRole = \App\Models\Role::where('slug', 'user')->first();
        if ($userRole) {
            $user->role_id = $userRole->id;
        }

        $user->save();

        $token = $user->createToken('auth_token')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
            ]
        ], 201);
    }
}
