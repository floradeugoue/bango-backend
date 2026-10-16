<?php

namespace App\Services\Auth;

use App\Exceptions\Auth\SocialAuthException;
use OpenSSLAsymmetricKey;

class JwtVerifierHelper
{
    /**
     * Decode and verify RS256 JWT token signature and claims.
     *
     * @param  array<string, mixed>|string|callable  $publicKeys  Either a PEM string, array of JWKs, or callback returning keys
     * @param array{
     *     issuers?: array<string>,
     *     audiences?: array<string>,
     *     nonce?: string|null,
     *     allow_clock_skew?: int
     * } $options
     * @return array<string, mixed> The payload claims
     *
     * @throws SocialAuthException
     */
    public static function verify(string $jwt, array|string|callable $publicKeys, array $options = []): array
    {
        $segments = explode('.', $jwt);

        if (count($segments) !== 3) {
            throw new SocialAuthException('Format du token JWT invalide.', 'malformed_token');
        }

        [$headerB64, $payloadB64, $signatureB64] = $segments;

        $headerJson = self::base64UrlDecode($headerB64);
        $payloadJson = self::base64UrlDecode($payloadB64);
        $signature = self::base64UrlDecode($signatureB64);

        if ($headerJson === null || $payloadJson === null || $signature === null) {
            throw new SocialAuthException('Encodage base64url du token JWT invalide.', 'malformed_token');
        }

        $header = json_decode($headerJson, true);
        $payload = json_decode($payloadJson, true);

        if (! is_array($header) || ! is_array($payload)) {
            throw new SocialAuthException('Contenu JSON du token JWT invalide.', 'malformed_token');
        }

        // 1. Verify algorithm (RS256 required)
        if (($header['alg'] ?? '') !== 'RS256') {
            throw new SocialAuthException("Algorithme de signature non pris en charge : {$header['alg']}.", 'invalid_algorithm');
        }

        // 2. Verify Expiration
        $clockSkew = $options['allow_clock_skew'] ?? 60;
        $now = time();

        if (isset($payload['exp']) && ($payload['exp'] + $clockSkew) < $now) {
            throw new SocialAuthException('Le jeton d\'identité a expiré.', 'expired_token');
        }

        // 3. Verify Not Before (nbf)
        if (isset($payload['nbf']) && ($payload['nbf'] - $clockSkew) > $now) {
            throw new SocialAuthException('Le jeton d\'identité n\'est pas encore actif.', 'token_not_active');
        }

        // 4. Verify Issuer
        if (! empty($options['issuers'])) {
            $iss = $payload['iss'] ?? null;
            if (! in_array($iss, $options['issuers'], true)) {
                throw new SocialAuthException("Émetteur (iss) invalide : {$iss}.", 'invalid_issuer');
            }
        }

        // 5. Verify Audience
        if (! empty($options['audiences'])) {
            $aud = $payload['aud'] ?? null;
            $audList = is_array($aud) ? $aud : [$aud];
            $matched = false;
            foreach ($audList as $audItem) {
                if (in_array($audItem, $options['audiences'], true)) {
                    $matched = true;
                    break;
                }
            }
            if (! $matched) {
                throw new SocialAuthException('Audience (aud) du token non reconnue.', 'invalid_audience');
            }
        }

        // 6. Verify Nonce if requested
        if (! empty($options['nonce'])) {
            $tokenNonce = $payload['nonce'] ?? null;
            if ($tokenNonce !== $options['nonce']) {
                throw new SocialAuthException('Le paramètre nonce ne correspond pas.', 'invalid_nonce');
            }
        }

        // 7. Verify cryptographic signature
        $dataToSign = "{$headerB64}.{$payloadB64}";
        $kid = $header['kid'] ?? null;

        $key = self::resolvePublicKey($publicKeys, $kid);

        if ($key === null) {
            throw new SocialAuthException('Clé publique correspondante introuvable pour vérifier la signature.', 'key_not_found');
        }

        $res = openssl_verify($dataToSign, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($res !== 1) {
            throw new SocialAuthException('La signature cryptographique du jeton d\'identité est invalide.', 'invalid_signature');
        }

        return $payload;
    }

    /**
     * Resolve OpenSSL public key from PEM, JWK array, or callback.
     */
    protected static function resolvePublicKey(array|string|callable $publicKeys, ?string $kid): ?OpenSSLAsymmetricKey
    {
        if (is_callable($publicKeys)) {
            $publicKeys = $publicKeys($kid);
        }

        if (is_string($publicKeys)) {
            $key = openssl_pkey_get_public($publicKeys);

            return $key !== false ? $key : null;
        }

        if (is_array($publicKeys)) {
            // Check if it's a list of JWKs: keys: [ { kid: ..., n: ..., e: ... } ]
            $keysList = $publicKeys['keys'] ?? $publicKeys;

            foreach ($keysList as $jwk) {
                if (! is_array($jwk)) {
                    continue;
                }

                if ($kid === null || (isset($jwk['kid']) && $jwk['kid'] === $kid)) {
                    if (isset($jwk['pem'])) {
                        $key = openssl_pkey_get_public($jwk['pem']);
                        if ($key !== false) {
                            return $key;
                        }
                    }

                    if (isset($jwk['n'], $jwk['e'])) {
                        $pem = self::jwkToPem($jwk['n'], $jwk['e']);
                        $key = openssl_pkey_get_public($pem);
                        if ($key !== false) {
                            return $key;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Convert RSA JWK modulus (n) and exponent (e) to PEM format.
     */
    public static function jwkToPem(string $n, string $e): string
    {
        $modulus = self::base64UrlDecode($n) ?? '';
        $exponent = self::base64UrlDecode($e) ?? '';

        $modSeq = self::encodeInteger($modulus).self::encodeInteger($exponent);
        $rsaPubKey = chr(0x30).self::encodeLength(strlen($modSeq)).$modSeq;
        $bitString = chr(0x03).self::encodeLength(strlen($rsaPubKey) + 1).chr(0x00).$rsaPubKey;
        $algorithmSeq = pack('H*', '300d06092a864886f70d0101010500'); // rsaEncryption + NULL
        $spki = chr(0x30).self::encodeLength(strlen($algorithmSeq) + strlen($bitString)).$algorithmSeq.$bitString;

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    protected static function encodeLength(int $length): string
    {
        if ($length <= 0x7F) {
            return chr($length);
        }
        if ($length <= 0xFF) {
            return chr(0x81).chr($length);
        }
        if ($length <= 0xFFFF) {
            return chr(0x82).pack('n', $length);
        }

        return chr(0x83).pack('N', $length);
    }

    protected static function encodeInteger(string $data): string
    {
        if (ord($data[0]) > 0x7F) {
            $data = chr(0x00).$data;
        }

        return chr(0x02).self::encodeLength(strlen($data)).$data;
    }

    /**
     * Base64URL decode with padding restoration.
     */
    public static function base64UrlDecode(string $data): ?string
    {
        $remainder = strlen($data) % 4;
        if ($remainder) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($data, '-_', '+/'), true);

        return $decoded !== false ? $decoded : null;
    }

    /**
     * Base64URL encode.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
