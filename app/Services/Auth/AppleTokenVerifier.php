<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SocialAuthException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

class AppleTokenVerifier
{
    /** @var (callable(?string): (array|string|null))|null */
    protected static $keysProvider = null;

    /**
     * Set custom public keys provider (for offline testing).
     */
    public static function setKeysProvider(?callable $provider): void
    {
        self::$keysProvider = $provider;
    }

    /**
     * Verify Apple identity token and return normalized claims.
     *
     * @return array{
     *     provider: 'apple',
     *     provider_user_id: string,
     *     email: string|null,
     *     email_verified: bool,
     *     name: string|null,
     *     avatar: string|null,
     *     is_private_email: bool,
     *     raw: array<string, mixed>
     * }
     *
     * @throws SocialAuthException
     */
    public function verify(string $identityToken, ?string $expectedNonce = null, ?string $clientProvidedName = null): array
    {
        $audiences = Config::get('services.apple.client_ids', []);
        $primaryClientId = Config::get('services.apple.client_id');
        if ($primaryClientId && ! in_array($primaryClientId, $audiences, true)) {
            $audiences[] = $primaryClientId;
        }

        if (empty($audiences) && ! app()->environment('testing')) {
            throw new SocialAuthException('La configuration Apple Sign In (Bundle ID / Service ID) est manquante sur le serveur.', 'unconfigured_provider', 500);
        }

        $keys = self::$keysProvider ? (self::$keysProvider)() : $this->getApplePublicKeys();

        $payload = JwtVerifierHelper::verify($identityToken, $keys, [
            'issuers' => ['https://appleid.apple.com'],
            'audiences' => $audiences,
            'nonce' => $expectedNonce,
        ]);

        $sub = $payload['sub'] ?? null;
        if (! $sub || ! is_string($sub)) {
            throw new SocialAuthException('Identifiant utilisateur subject (sub) Apple manquant.', 'invalid_subject');
        }

        $email = isset($payload['email']) ? strtolower((string) $payload['email']) : null;
        $isPrivateEmail = $email !== null && str_ends_with($email, 'privaterelay.appleid.com');

        $emailVerified = true;
        if (isset($payload['email_verified'])) {
            $emailVerified = filter_var($payload['email_verified'], FILTER_VALIDATE_BOOLEAN);
        }

        return [
            'provider' => 'apple',
            'provider_user_id' => (string) $sub,
            'email' => $email,
            'email_verified' => $emailVerified,
            'name' => $clientProvidedName,
            'avatar' => null,
            'is_private_email' => $isPrivateEmail,
            'raw' => $payload,
        ];
    }

    /**
     * Fetch Apple's official JWKS keys.
     */
    protected function getApplePublicKeys(): array
    {
        return Cache::remember('apple.auth.public_keys', 86400, function () {
            $response = Http::timeout(5)->get('https://appleid.apple.com/auth/keys');
            if (! $response->successful()) {
                throw new SocialAuthException('Impossible de récupérer les clés publiques de validation Apple.', 'key_fetch_failed', 503);
            }

            return $response->json();
        });
    }
}
