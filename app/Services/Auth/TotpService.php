<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class TotpService
{
    public const TIME_STEP = 30;

    public const DIGITS = 6;

    public const DISCREPANCY = 1;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Generate a new 160-bit (20-byte) cryptographically secure Base32 secret.
     */
    public function generateSecret(): string
    {
        $randomBytes = random_bytes(20);

        return $this->base32Encode($randomBytes);
    }

    /**
     * Generate standard RFC 6238 otpauth:// URI for authenticator applications (Google Authenticator, Apple Keychain, 1Password, etc.).
     */
    public function getOtpAuthUri(string $secret, string $accountName, string $issuer = 'BANGO'): string
    {
        $encodedIssuer = rawurlencode($issuer);
        $encodedAccount = rawurlencode($accountName);
        $cleanSecret = strtoupper(trim($secret));

        return "otpauth://totp/{$encodedIssuer}:{$encodedAccount}?secret={$cleanSecret}&issuer={$encodedIssuer}&algorithm=SHA1&digits=6&period=30";
    }

    /**
     * Verify a 6-digit TOTP code against a Base32 secret with anti-replay protection.
     *
     * @param  string  $secret  Raw Base32 secret
     * @param  string  $code  6-digit user input code
     * @param  int|string|null  $userId  Optional user ID for anti-replay cache locking
     * @param  int  $discrepancy  Time step tolerance (default: 1, i.e. +-30s)
     */
    public function verifyCode(string $secret, string $code, int|string|null $userId = null, int $discrepancy = self::DISCREPANCY): bool
    {
        $code = trim($code);

        if (! preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $currentTime = time();
        $currentStep = (int) floor($currentTime / self::TIME_STEP);

        for ($i = -$discrepancy; $i <= $discrepancy; $i++) {
            $step = $currentStep + $i;
            $calculatedCode = $this->calculateTotpForStep($secret, $step);

            if (hash_equals($calculatedCode, $code)) {
                // Anti-replay protection: prevent reusing the same valid code within the time window
                if ($userId !== null) {
                    $cacheKey = "totp_used:{$userId}:{$step}";
                    if (Cache::has($cacheKey)) {
                        return false; // Code already used in this time slice
                    }

                    // Lock this step for (2 * discrepancy + 1) * 30 seconds
                    $lockSeconds = ($discrepancy + 2) * self::TIME_STEP;
                    Cache::put($cacheKey, true, now()->addSeconds($lockSeconds));
                }

                return true;
            }
        }

        return false;
    }

    /**
     * Verify TOTP code for a registered User using their encrypted two_factor_secret.
     */
    public function verifyUser(User $user, string $code): bool
    {
        if (empty($user->two_factor_secret)) {
            return false;
        }

        try {
            $plainSecret = Crypt::decryptString($user->two_factor_secret);
        } catch (\Throwable) {
            // In case secret was previously stored in plaintext or invalid ciphertext
            $plainSecret = $user->two_factor_secret;
        }

        return $this->verifyCode($plainSecret, $code, $user->id);
    }

    /**
     * Calculate 6-digit TOTP for a specific 30s step using RFC 4226 / RFC 6238 HMAC-SHA1.
     */
    public function calculateTotpForStep(string $secret, int $step): string
    {
        $binarySecret = $this->base32Decode($secret);

        // Pack step into 8-byte big-endian binary counter
        $binaryStep = pack('N*', 0).pack('N*', $step);

        $hash = hash_hmac('sha1', $binaryStep, $binarySecret, true);

        // Dynamic truncation (RFC 4226 section 5.4)
        $offset = ord($hash[19]) & 0x0F;
        $binary = (
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF)
        );

        $totp = $binary % 1000000;

        return str_pad((string) $totp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Encode binary string to standard RFC 4648 Base32.
     */
    public function base32Encode(string $data): string
    {
        if (empty($data)) {
            return '';
        }

        $binary = '';
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $binary .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $base32 = '';
        $chunks = str_split($binary, 5);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }
            $base32 .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $base32;
    }

    /**
     * Decode standard RFC 4648 Base32 string to binary data.
     */
    public function base32Decode(string $base32): string
    {
        $base32 = strtoupper(trim($base32));
        $base32 = rtrim($base32, '=');

        if (empty($base32)) {
            return '';
        }

        $binary = '';
        $len = strlen($base32);
        for ($i = 0; $i < $len; $i++) {
            $char = $base32[$i];
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                throw new RuntimeException("Invalid Base32 character: '{$char}'");
            }
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        $chunks = str_split($binary, 8);
        foreach ($chunks as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
