<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SignupRequest;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SignupController extends Controller
{
    public function __invoke(SignupRequest $request): JsonResponse
    {
        // Custom password validation since FormRequest handles the error format
        $pwd = $request->password;
        $failedRules = [];
        if (strlen($pwd) < 8) {
            $failedRules[] = 'min-length';
        }
        if (! preg_match('/\d/', $pwd)) {
            $failedRules[] = 'needs-digit';
        }
        if (preg_match('/1234|abcd|qwerty|qwertz|azerty/i', $pwd)) {
            $failedRules[] = 'no-sequence';
        }

        if (! empty($failedRules)) {
            return response()->json([
                'kind' => 'weak-password',
                'failed' => $failedRules,
            ], 422);
        }

        $rawBirthDate = $request->input('birthDate') ?? $request->input('birth_date');
        if ($rawBirthDate) {
            $parsed = rescue(fn () => Carbon::parse($rawBirthDate));
            if ($parsed && $parsed->isAfter(Carbon::now()->subYears(18))) {
                return response()->json([
                    'message' => 'L\'inscription est réservée aux personnes majeures (18 ans et plus).',
                    'errors' => [
                        'birthDate' => ['Vous devez avoir au moins 18 ans pour utiliser BANGO.'],
                    ],
                ], 422);
            }
        }

        $user = new User;
        $user->name = $request->name ?? explode('@', $request->email)[0];
        $user->email = $request->email;
        $user->password = Hash::make($request->password);
        if ($rawBirthDate && $parsed) {
            $user->birth_date = $parsed->toDateString();
        }

        $userRole = Role::firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'Utilisateur', 'description' => 'Utilisateur standard']
        );
        $user->role_id = $userRole->id;

        $localPart = explode('@', $request->email)[0];
        $baseHandle = strtolower(preg_replace('/[^a-z0-9.]/', '', Str::slug($localPart, '.')));
        if (strlen($baseHandle) < 3) {
            $baseHandle = 'user'.random_int(100, 999);
        }
        $candidate = substr($baseHandle, 0, 16);
        $counter = 1;
        while (User::withTrashed()->where('handle', $candidate)->exists()) {
            $candidate = substr($baseHandle, 0, 14).$counter;
            $counter++;
        }
        $user->handle = $candidate;
        $user->display_name = $user->name;

        $user->save();

        dispatch(function () use ($user) {
            try {
                $user->sendWelcomeEmailVerificationNotification();
            } catch (\Throwable $e) {
                Log::warning('Échec envoi email de bienvenue à l\'inscription : '.$e->getMessage());
            }
        })->afterResponse();

        if (! empty($user->name)) {
            app(AutomatedTextFilterService::class)->scanText($user->name, 'user', $user->id, $user);
        }

        $token = $user->createToken('Application mobile')->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'email' => $user->email,
                'handle' => $user->handle,
                'displayName' => $user->display_name,
                'email_verified' => false,
                'emailVerified' => false,
            ],
        ], 201);
    }

    /**
     * Annule une inscription en cours (LG-09) : efface le compte et révoque ses jetons.
     * DELETE /api/auth/signup
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->delete();
            $user->forceDelete();
        }

        return response()->json(null, 204);
    }
}
