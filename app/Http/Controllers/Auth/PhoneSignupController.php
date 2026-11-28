<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Auth\Role;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppCloudService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class PhoneSignupController extends Controller
{
    public function __construct(
        protected WhatsAppCloudService $whatsAppService
    ) {}

    /**
     * Start the phone signup flow by verifying number uniqueness, applying rate limits,
     * generating an OTP challenge, and sending the verification code via WhatsApp Cloud API.
     */
    public function start(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string'],
            'channel' => ['nullable', 'string', 'in:whatsapp'],
        ]);

        $rawPhone = trim($validated['phone']);
        // Normalize phone number to standard international format (+237...)
        $digits = preg_replace('/\D/', '', $rawPhone);
        if (empty($digits) || strlen($digits) < 8 || strlen($digits) > 16) {
            return response()->json([
                'message' => 'Le numéro de téléphone fourni est invalide.',
                'errors' => [
                    'phone' => ['Le numéro de téléphone doit être au format international valide (ex: +237690000000).'],
                ],
            ], 422);
        }

        $normalizedPhone = '+'.$digits;

        // 1. Refuser si un compte existe déjà avec ce numéro (409)
        $alreadyTaken = User::where('phone', $normalizedPhone)
            ->orWhere('phone', $digits)
            ->exists();

        if ($alreadyTaken) {
            return response()->json([
                'kind' => 'phone-taken',
                'message' => 'Un compte BANGO existe déjà avec ce numéro de téléphone.',
            ], 409);
        }

        // 2. Limite par numéro : au plus 3 envois par heure (429)
        $phoneRateKey = 'signup_phone:rate:'.$digits;
        if (RateLimiter::tooManyAttempts($phoneRateKey, 3)) {
            $seconds = RateLimiter::availableIn($phoneRateKey);

            return response()->json([
                'kind' => 'rate_limited',
                'retry_after' => $seconds,
                'message' => "Trop de demandes d'envoi pour ce numéro. Veuillez réessayer dans {$seconds} secondes.",
            ], 429);
        }

        // 3. Limite par adresse IP : 10 requêtes par minute (429)
        $ipRateKey = 'signup_phone:ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($ipRateKey, 10)) {
            $seconds = RateLimiter::availableIn($ipRateKey);

            return response()->json([
                'kind' => 'rate_limited',
                'retry_after' => $seconds,
                'message' => "Trop de requêtes depuis cette adresse IP. Veuillez patienter {$seconds} secondes.",
            ], 429);
        }

        RateLimiter::hit($phoneRateKey, 3600);
        RateLimiter::hit($ipRateKey, 60);

        // 4. Générer le code à 6 chiffres et le challenge unique
        $code = (string) random_int(100000, 999999);
        $challenge = Str::random(40);
        $ttlSeconds = 600; // 10 minutes

        $challengeData = [
            'phone' => $normalizedPhone,
            'code_hash' => Hash::make($code),
            'attempts_left' => 5,
            'expires_at' => now()->addSeconds($ttlSeconds)->timestamp,
        ];

        Cache::put('signup_challenge:'.$challenge, $challengeData, $ttlSeconds);

        // 5. Envoi du code par WhatsApp Cloud API
        $this->whatsAppService->sendOtp($normalizedPhone, $code);

        return response()->json([
            'challenge' => $challenge,
            'expires_in' => $ttlSeconds,
        ], 200);
    }

    /**
     * Verify the OTP challenge and create the new user account.
     */
    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'persistent' => ['nullable', 'boolean'],
        ]);

        $challengeKey = 'signup_challenge:'.$validated['challenge'];
        $challengeData = Cache::get($challengeKey);

        // 1. Si le challenge n'existe plus ou a expiré (422 expired)
        if (! $challengeData) {
            return response()->json([
                'kind' => 'expired',
                'message' => 'Le code de vérification a expiré ou est invalide. Veuillez recommencer.',
            ], 422);
        }

        $enteredCode = trim($validated['code']);

        // 2. Vérification du code (422 invalid)
        if (! Hash::check($enteredCode, $challengeData['code_hash'])) {
            $attemptsLeft = $challengeData['attempts_left'] - 1;

            if ($attemptsLeft <= 0) {
                Cache::forget($challengeKey);

                return response()->json([
                    'kind' => 'invalid',
                    'attemptsLeft' => 0,
                    'message' => 'Nombre maximal de tentatives dépassé. Veuillez demander un nouveau code.',
                ], 422);
            }

            $challengeData['attempts_left'] = $attemptsLeft;
            $remainingSeconds = max(1, $challengeData['expires_at'] - now()->timestamp);
            Cache::put($challengeKey, $challengeData, $remainingSeconds);

            return response()->json([
                'kind' => 'invalid',
                'attemptsLeft' => $attemptsLeft,
                'message' => "Code incorrect. Il vous reste {$attemptsLeft} tentative(s).",
            ], 422);
        }

        // 3. Code valide : consommation immédiate du challenge
        Cache::forget($challengeKey);

        $phone = $challengeData['phone'];
        $digits = preg_replace('/\D/', '', $phone);

        // Vérification de sécurité contre les courses d'accès
        if (User::where('phone', $phone)->orWhere('phone', $digits)->exists()) {
            return response()->json([
                'kind' => 'phone-taken',
                'message' => 'Un compte BANGO existe déjà avec ce numéro de téléphone.',
            ], 409);
        }

        // 4. Création du compte utilisateur
        $userRole = Role::firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'Utilisateur', 'description' => 'Utilisateur standard']
        );

        $handle = $this->generateAvailableHandle($phone);

        $user = User::create([
            'name' => 'Utilisateur Bango',
            'display_name' => 'Utilisateur Bango',
            'phone' => $phone,
            'phone_verified_at' => now(),
            'email' => "phone_{$digits}@phone.bango.internal",
            'password' => Hash::make(Str::random(32)),
            'handle' => $handle,
            'role_id' => $userRole->id,
            'status' => 'active',
            'is_verified' => false,
        ]);

        $deviceName = $request->input('device_name') ?: 'Application mobile';
        $token = $user->createToken($deviceName)->accessToken;

        return response()->json([
            'token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name,
                'displayName' => $user->display_name,
                'phone' => $user->phone,
                'phone_verified' => true,
                'phoneVerified' => true,
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'phoneVerifiedAt' => $user->phone_verified_at?->toIso8601String(),
                'handle' => $user->handle,
                'email' => null,
                'is_verified' => (bool) $user->is_verified,
                'isVerified' => (bool) $user->is_verified,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'created' => true,
        ], 201);
    }

    /**
     * Generate an available and unique handle for phone-registered user.
     */
    protected function generateAvailableHandle(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);
        $suffix = substr($digits, -4) ?: (string) random_int(1000, 9999);
        $candidate = 'user'.$suffix;

        while (User::withTrashed()->where('handle', $candidate)->exists()) {
            $candidate = 'user'.random_int(10000, 99999);
        }

        return $candidate;
    }
}
