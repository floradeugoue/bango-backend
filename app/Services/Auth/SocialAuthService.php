<?php

namespace App\Services\Auth;

use App\Enums\OtpChannel;
use App\Exceptions\Auth\SocialAuthException;
use App\Models\Auth\OAuthAccount;
use App\Models\User;
use App\Services\Otp\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

class SocialAuthService
{
    public function __construct(
        protected GoogleTokenVerifier $googleVerifier,
        protected AppleTokenVerifier $appleVerifier,
        protected OtpService $otpService
    ) {}

    /**
     * Process social authentication or account linking.
     *
     * @return array{
     *     status: int,
     *     data: array<string, mixed>
     * }
     *
     * @throws SocialAuthException
     */
    public function authenticate(
        string $provider,
        string $idToken,
        ?string $expectedNonce = null,
        ?string $clientName = null,
        ?User $authUser = null,
        string $deviceName = 'Application mobile',
        bool $persistent = true
    ): array {
        $provider = strtolower(trim($provider));

        // 1. Verify token cryptographically
        $claims = match ($provider) {
            'google' => $this->googleVerifier->verify($idToken, $expectedNonce),
            'apple' => $this->appleVerifier->verify($idToken, $expectedNonce, $clientName),
            default => throw new SocialAuthException("Fournisseur d'identité non supporté : {$provider}.", 'unknown_provider', 422),
        };

        $providerUserId = $claims['provider_user_id'];
        $email = $claims['email'] ? strtolower(trim($claims['email'])) : null;
        $name = $claims['name'] ?? $clientName;
        $avatar = $claims['avatar'] ?? null;

        // 2. Case A: Authenticated user explicitly links this social account
        if ($authUser !== null) {
            return $this->linkAccount($authUser, $provider, $providerUserId, $email, $name, $avatar, $claims['raw']);
        }

        // 3. Case B: Unauthenticated user logging in or signing up
        $existingOAuth = OAuthAccount::with('user')->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existingOAuth && $existingOAuth->user) {
            return $this->handleExistingUserSignIn($existingOAuth->user, $deviceName, $persistent);
        }

        // 4. Check if a BANGO account already exists with this email
        if ($email !== null) {
            $existingUserWithEmail = User::withTrashed()->where('email', $email)->first();

            if ($existingUserWithEmail) {
                // Strict rule: DO NOT silently auto-merge by email alone!
                return [
                    'status' => 409,
                    'data' => [
                        'kind' => 'account_exists_unlinked',
                        'message' => 'Un compte BANGO existe déjà avec cette adresse email. Veuillez vous connecter avec votre mot de passe pour lier ce compte social.',
                        'email' => $this->maskEmail($email),
                        'provider' => $provider,
                    ],
                ];
            }
        }

        // 5. Create new account with OAuth account
        return $this->createNewAccount($provider, $providerUserId, $email, $name, $avatar, $claims['raw'], $deviceName, $persistent);
    }

    /**
     * Explicitly link a provider account to an authenticated user.
     */
    protected function linkAccount(
        User $user,
        string $provider,
        string $providerUserId,
        ?string $email,
        ?string $name,
        ?string $avatar,
        array $rawClaims
    ): array {
        $existing = OAuthAccount::where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();

        if ($existing) {
            if ($existing->user_id === $user->id) {
                return [
                    'status' => 200,
                    'data' => [
                        'linked' => true,
                        'message' => 'Ce compte est déjà associé à votre profil.',
                        'user' => $this->formatUserPayload($user),
                    ],
                ];
            }

            throw new SocialAuthException(
                'Ce compte social est déjà associé à un autre utilisateur.',
                'account_already_linked',
                409
            );
        }

        OAuthAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_user_id' => $providerUserId,
            'email' => $email,
            'name' => $name,
            'avatar' => $avatar,
            'raw_claims' => $rawClaims,
        ]);

        return [
            'status' => 200,
            'data' => [
                'linked' => true,
                'message' => 'Le compte social a été associé avec succès à votre profil.',
                'user' => $this->formatUserPayload($user),
            ],
        ];
    }

    /**
     * Handle sign-in for an already linked account.
     */
    protected function handleExistingUserSignIn(User $user, string $deviceName, bool $persistent): array
    {
        if ($user->trashed()) {
            if ($user->scheduled_for_deletion_at && $user->scheduled_for_deletion_at->isFuture()) {
                // Restoration within retraction window (<30 days)
                $user->restore();
                $user->update(['status' => 'active', 'scheduled_for_deletion_at' => null]);
            } else {
                return [
                    'status' => 403,
                    'data' => ['kind' => 'deleted', 'message' => 'Ce compte a été définitivement supprimé.'],
                ];
            }
        }

        if ($user->status === 'blocked') {
            return [
                'status' => 403,
                'data' => ['kind' => 'blocked', 'message' => 'Ce compte a été bloqué.'],
            ];
        }

        if ($user->status === 'suspended') {
            return [
                'status' => 403,
                'data' => ['kind' => 'suspended', 'message' => 'Ce compte est actuellement suspendu.'],
            ];
        }

        if ($user->status === 'paused') {
            $user->update(['status' => 'active']);
        }

        // 2FA Challenge check
        if (! empty($user->two_factor_method) && $user->two_factor_method !== 'off') {
            return $this->initiateTwoFactorChallenge($user, $deviceName, $persistent);
        }

        // Direct sign-in without 2FA
        $token = $this->issueAccessToken($user, $deviceName, $persistent);

        return [
            'status' => 200,
            'data' => [
                'token' => $token,
                'user' => $this->formatUserPayload($user),
                'created' => false,
                'requires_profile_completion' => empty($user->handle) || empty($user->phone_verified_at),
            ],
        ];
    }

    /**
     * Create a brand new account and link the OAuth provider identity.
     */
    protected function createNewAccount(
        string $provider,
        string $providerUserId,
        ?string $email,
        ?string $name,
        ?string $avatar,
        array $rawClaims,
        string $deviceName,
        bool $persistent
    ): array {
        try {
            return DB::transaction(function () use ($provider, $providerUserId, $email, $name, $avatar, $rawClaims, $deviceName, $persistent) {
                // Double check in transaction to prevent race conditions
                $existing = OAuthAccount::with('user')->where('provider', $provider)
                    ->where('provider_user_id', $providerUserId)
                    ->first();

                if ($existing && $existing->user) {
                    return $this->handleExistingUserSignIn($existing->user, $deviceName, $persistent);
                }

                $handle = $this->generateAvailableHandle($name ?? $email);

                $user = User::create([
                    'name' => $name ?? 'Utilisateur Bango',
                    'email' => $email ?? "{$providerUserId}@{$provider}.bango.internal",
                    'password' => Hash::make(Str::random(32)),
                    'handle' => $handle,
                    'display_name' => $name,
                    'avatar_url' => $avatar,
                    'status' => 'active',
                    'is_verified' => false,
                    'email_verified_at' => $email ? now() : null,
                ]);

                OAuthAccount::create([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_user_id' => $providerUserId,
                    'email' => $email,
                    'name' => $name,
                    'avatar' => $avatar,
                    'raw_claims' => $rawClaims,
                ]);

                $token = $this->issueAccessToken($user, $deviceName, $persistent);

                return [
                    'status' => 201,
                    'data' => [
                        'token' => $token,
                        'user' => $this->formatUserPayload($user),
                        'created' => true,
                        'requires_profile_completion' => true,
                    ],
                ];
            });
        } catch (QueryException $e) {
            // In case of concurrent request unique constraint collision
            $existing = OAuthAccount::with('user')->where('provider', $provider)
                ->where('provider_user_id', $providerUserId)
                ->first();

            if ($existing && $existing->user) {
                return $this->handleExistingUserSignIn($existing->user, $deviceName, $persistent);
            }

            throw $e;
        }
    }

    /**
     * Generate an initial available handle matching BANGO handle rules.
     */
    protected function generateAvailableHandle(?string $seed): string
    {
        $base = 'user';
        if ($seed) {
            $cleaned = preg_replace('/[^a-z0-9]/', '', strtolower($seed));
            if (strlen($cleaned) >= 3) {
                $base = substr($cleaned, 0, 12);
            }
        }

        $candidate = $base;
        $attempt = 1;

        while (
            strlen($candidate) < 3 ||
            User::where('handle', $candidate)->exists() ||
            in_array($candidate, ['admin', 'bango', 'support', 'root'], true)
        ) {
            $suffix = (string) rand(100, 9999);
            $candidate = substr($base, 0, 20 - strlen($suffix) - 1).'.'.$suffix;
            $attempt++;
            if ($attempt > 20) {
                $candidate = 'user.'.Str::lower(Str::random(8));
                break;
            }
        }

        return $candidate;
    }

    /**
     * Trigger 2FA challenge matching SigninController.
     */
    protected function initiateTwoFactorChallenge(User $user, string $deviceName, bool $persistent): array
    {
        $isApp = in_array($user->two_factor_method, ['app', 'totp'], true);
        $isEmail = $user->two_factor_method === 'email';

        if ($isApp) {
            $channel = 'app';
            $destination = $user->email ?: 'authenticator-app';
        } elseif ($isEmail) {
            $channel = 'email';
            $destination = $user->email;
        } else {
            $channel = 'sms';
            $destination = $user->phone ?: $user->email;
        }

        $challengeId = Str::random(40);
        Cache::put('2fa.challenge.'.$challengeId, [
            'user_id' => $user->id,
            'persistent' => $persistent,
            'device_name' => $deviceName,
            'destination' => $destination,
            'channel' => $channel,
        ], now()->addMinutes(10));

        if ($user->email) {
            Cache::put('2fa.challenge.id.'.$user->email, $challengeId, now()->addMinutes(10));
        }
        if ($user->phone) {
            Cache::put('2fa.challenge.id.'.$user->phone, $challengeId, now()->addMinutes(10));
        }

        if (in_array($channel, ['email', 'sms'], true) && $destination) {
            $otpChannel = $channel === 'email' ? OtpChannel::Email : OtpChannel::Sms;
            $otpResult = $this->otpService->sendOtp($destination, $otpChannel, 'second_factor');

            if (is_array($otpResult)) {
                Cache::forget('2fa.challenge.'.$challengeId);

                return [
                    'status' => $otpResult['status'],
                    'data' => $otpResult['data'],
                ];
            }
        }

        return [
            'status' => 200,
            'data' => [
                'kind' => 'second-factor',
                'destination' => $this->maskDestination($destination, $channel),
                'challenge' => $challengeId,
            ],
        ];
    }

    protected function issueAccessToken(User $user, string $deviceName, bool $persistent): string
    {
        if ($persistent) {
            Passport::personalAccessTokensExpireIn(now()->addYear());
        } else {
            Passport::personalAccessTokensExpireIn(now()->addDays(7));
        }

        return $user->createToken($deviceName)->accessToken;
    }

    protected function formatUserPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'handle' => $user->handle,
            'display_name' => $user->display_name ?? $user->name,
            'avatar_url' => $user->avatar_url,
            'is_verified' => (bool) $user->is_verified,
        ];
    }

    protected function maskDestination(string $target, string $method): string
    {
        if ($method === 'app') {
            return 'Application Authenticator';
        }

        if (str_contains($target, '@')) {
            return $this->maskEmail($target);
        }

        $len = strlen($target);
        if ($len > 6) {
            return substr($target, 0, 4).' '.str_repeat('*', $len - 6).substr($target, -2);
        }

        return $target;
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1] ?? '';
        $len = strlen($name);
        $maskedName = $len > 2 ? substr($name, 0, 1).str_repeat('*', $len - 2).substr($name, -1) : $name.'***';

        return "{$maskedName}@{$domain}";
    }
}
