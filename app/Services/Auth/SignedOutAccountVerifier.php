<?php

namespace App\Services\Auth;

use App\Models\Support\UserAccountSanction;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Vérifie l'identité d'un compte privé de session (suspendu ou supprimé)
 * à partir de son identifiant et de son mot de passe, avec limitation de débit.
 *
 * Utilisé par POST /auth/account-state, /auth/appeal et /auth/support-ticket.
 */
class SignedOutAccountVerifier
{
    public const MAX_FAILED_ATTEMPTS = 5;

    public const LOCKOUT_SECONDS = 900;

    public const MAX_SUBMISSIONS_PER_HOUR = 5;

    /**
     * Retrouve le compte (y compris supprimé) et vérifie le mot de passe.
     *
     * @throws HttpResponseException 429 si trop d'échecs, 401 si identifiants invalides
     */
    public function verify(string $identifier, string $password, string $ip): User
    {
        $key = $this->failedAttemptsKey($identifier, $ip);

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILED_ATTEMPTS)) {
            $this->throwLocked(RateLimiter::availableIn($key));
        }

        $user = User::withTrashed()
            ->where(function ($query) use ($identifier) {
                $query->where('email', $identifier)->orWhere('phone', $identifier);
            })
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, self::LOCKOUT_SECONDS);

            throw new HttpResponseException(response()->json([
                'kind' => 'invalid-credentials',
                'attemptsLeft' => RateLimiter::retriesLeft($key, self::MAX_FAILED_ATTEMPTS),
            ], 401));
        }

        RateLimiter::clear($key);

        return $user;
    }

    /**
     * Limite le nombre de recours / tickets envoyés hors session par un même compte.
     *
     * @throws HttpResponseException 429 si le quota horaire est atteint
     */
    public function ensureCanSubmit(User $user, string $scope): void
    {
        $key = "signed-out-{$scope}.{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::MAX_SUBMISSIONS_PER_HOUR)) {
            $this->throwLocked(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 3600);
    }

    /**
     * Sanction de suspension actuellement active pour le compte, la plus récente d'abord.
     */
    public function activeSuspension(User $user): ?UserAccountSanction
    {
        return $user->sanctions()
            ->where('type', 'suspension')
            ->where('is_active', true)
            ->first();
    }

    private function failedAttemptsKey(string $identifier, string $ip): string
    {
        return 'signed-out-account.'.Str::transliterate(Str::lower($identifier)).'|'.$ip;
    }

    /**
     * @throws HttpResponseException
     */
    private function throwLocked(int $seconds): never
    {
        throw new HttpResponseException(response()->json([
            'kind' => 'locked',
            'minutes' => (int) ceil($seconds / 60),
            'until' => now()->addSeconds($seconds)->toIso8601String(),
        ], 429));
    }
}
