<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SocialAuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class GoogleTokenVerifier
{
    /** @var (callable(?string): (array|string|null))|null */
    protected static $keysProvider = null;

    /**
     * Set a custom public keys provider (useful for offline testing).
     */
    public static function setKeysProvider(?callable $provider): void
    {
        self::$keysProvider = $provider;
    }

    /**
     * Verify Google ID token and return normalized user claims.
     *
     * @return array{
     *     provider: 'google',
     *     provider_user_id: string,
     *     email: string|null,
     *     email_verified: bool,
     *     name: string|null,
     *     avatar: string|null,
     *     raw: array<string, mixed>
     * }
     *
     * @throws SocialAuthException
     */
    public function verify(string $idToken, ?string $expectedNonce = null): array
    {
        $audiences = Config::get('services.google.client_ids', []);
        $primaryClientId = Config::get('services.google.client_id');
        if ($primaryClientId && ! in_array($primaryClientId, $audiences, true)) {
            $audiences[] = $primaryClientId;
        }

        if (empty($audiences) && ! app()->environment('testing')) {
            throw new SocialAuthException('La configuration Google OAuth (Client IDs) est manquante sur le serveur.', 'unconfigured_provider', 500);
        }

        $keys = self::$keysProvider ? (self::$keysProvider)() : $this->getGooglePublicKeys();

        $payload = JwtVerifierHelper::verify($idToken, $keys, [
            'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
            'audiences' => $audiences,
            'nonce' => $expectedNonce,
        ]);

        $sub = $payload['sub'] ?? null;
        if (! $sub || ! is_string($sub)) {
            throw new SocialAuthException('Identifiant utilisateur subject (sub) Google manquant.', 'invalid_subject');
        }

        $emailVerified = filter_var($payload['email_verified'] ?? true, FILTER_VALIDATE_BOOLEAN);
        if (isset($payload['email_verified']) && ! $emailVerified) {
            throw new SocialAuthException('L\'adresse email Google associée n\'est pas vérifiée.', 'unverified_email');
        }

        return [
            'provider' => 'google',
            'provider_user_id' => (string) $sub,
            'email' => isset($payload['email']) ? strtolower((string) $payload['email']) : null,
            'email_verified' => $emailVerified,
            'name' => isset($payload['name']) ? (string) $payload['name'] : null,
            'avatar' => isset($payload['picture']) ? (string) $payload['picture'] : null,
            'raw' => $payload,
        ];
    }

    /**
     * Fetch Google's official public JWKS keys.
     */
    protected function getGooglePublicKeys(): array
    {
        return Cache::remember('google.oauth2.public_keys', 86400, function () {
            $response = Http::timeout(5)->get('https://www.googleapis.com/oauth2/v3/certs');
            if (! $response->successful()) {
                throw new SocialAuthException('Impossible de récupérer les clés publiques de validation Google.', 'key_fetch_failed', 503);
            }

            return $response->json();
        });
    }
}
