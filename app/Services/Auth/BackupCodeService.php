<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

class BackupCodeService
{
    public const CODE_COUNT = 8;

    /**
     * Standard alphabet without confusing characters: no I, O, 0, or 1.
     */
    public const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Generate 8 cryptographically secure, unguessable single-use backup codes.
     *
     * @return array{plain: array<string>, hashed: array<string>}
     */
    public function generateCodes(): array
    {
        $plainCodes = [];
        $hashedCodes = [];

        for ($i = 0; $i < self::CODE_COUNT; $i++) {
            $code = $this->generateSingleCode();
            $plainCodes[] = $code;
            $hashedCodes[] = hash('sha256', $code);
        }

        return [
            'plain' => $plainCodes,
            'hashed' => $hashedCodes,
        ];
    }

    /**
     * Format a single code: XXXX-XXXX (e.g. 7K2P-91MD).
     */
    protected function generateSingleCode(): string
    {
        $bytes = random_bytes(8);
        $chars = '';
        $alphabetLen = strlen(self::ALPHABET);

        for ($j = 0; $j < 8; $j++) {
            $chars .= self::ALPHABET[ord($bytes[$j]) % $alphabetLen];
        }

        return substr($chars, 0, 4).'-'.substr($chars, 4, 4);
    }

    /**
     * Normalize a backup code input by stripping whitespace and hyphens and converting to uppercase.
     */
    public function normalizeCode(string $code): string
    {
        return preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($code))) ?? '';
    }

    /**
     * Atomically verify and consume a backup code using row-level locking.
     *
     * If the code matches one of the stored hashed backup codes, it is immediately
     * removed from the user's account and cannot be reused.
     */
    public function verifyAndConsume(User $user, string $code): bool
    {
        $clean = $this->normalizeCode($code);

        if (strlen($clean) !== 8) {
            return false;
        }

        $formatted = substr($clean, 0, 4).'-'.substr($clean, 4, 4);

        $hashWithHyphen = hash('sha256', $formatted);
        $hashWithoutHyphen = hash('sha256', $clean);

        return DB::transaction(function () use ($user, $hashWithHyphen, $hashWithoutHyphen, $formatted, $clean) {
            /** @var User|null $lockedUser */
            $lockedUser = User::where('id', $user->id)->lockForUpdate()->first();

            if (! $lockedUser || empty($lockedUser->backup_codes) || ! is_array($lockedUser->backup_codes)) {
                return false;
            }

            $backupCodes = $lockedUser->backup_codes;
            $matchedIndex = null;

            foreach ($backupCodes as $idx => $entry) {
                $storedHash = is_array($entry) ? ($entry['hash'] ?? $entry['code'] ?? '') : (string) $entry;

                // Match against SHA-256 hash or legacy plaintext fallback
                if (
                    hash_equals($storedHash, $hashWithHyphen) ||
                    hash_equals($storedHash, $hashWithoutHyphen) ||
                    hash_equals($storedHash, $formatted) ||
                    hash_equals($storedHash, $clean)
                ) {
                    $matchedIndex = $idx;
                    break;
                }
            }

            if ($matchedIndex === null) {
                return false;
            }

            // Atomically remove the consumed code
            unset($backupCodes[$matchedIndex]);
            $updatedCodes = array_values($backupCodes);
            $lockedUser->backup_codes = $updatedCodes;
            $lockedUser->save();

            // Keep in-memory model in sync
            $user->backup_codes = $updatedCodes;

            return true;
        });

    }

    /**
     * Check how many backup codes remain for a given user.
     */
    public function getRemainingCount(User $user): int
    {
        if (empty($user->backup_codes) || ! is_array($user->backup_codes)) {
            return 0;
        }

        return count($user->backup_codes);
    }
}
