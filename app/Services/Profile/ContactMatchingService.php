<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ContactMatchingService
{
    public const MAX_BATCH_SIZE = 500;

    /**
     * Compute legacy JavaScript 32-bit polynomial hash for mobile client compatibility.
     *
     * Matches `let hash = 0; for (let i = 0; i < digits.length; i++) hash = (hash * 31 + charCode) | 0; return hash.toString(16);`
     */
    public static function computeLegacyJsHash(string $digits): string
    {
        $hash = 0;
        $len = strlen($digits);
        for ($i = 0; $i < $len; $i++) {
            $code = ord($digits[$i]);
            $hash = (($hash * 31) + $code) & 0xFFFFFFFF;
            if ($hash >= 0x80000000) {
                $hash -= 0x100000000;
            }
        }

        return dechex($hash & 0xFFFFFFFF);
    }

    /**
     * Generate all known cryptographic and representation signatures for a phone number.
     *
     * @return array<string>
     */
    public static function generateSignaturesForPhone(string $phone, ?string $salt = null): array
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (empty($digits)) {
            return [];
        }

        $signatures = [
            $phone,
            $digits,
            '+'.$digits,
            hash('sha256', $phone),
            hash('sha256', $digits),
            hash('sha256', '+'.$digits),
        ];

        if (strlen($digits) >= 8) {
            $last8 = substr($digits, -8);
            $signatures[] = $last8;
            $signatures[] = hash('sha256', $last8);
            $signatures[] = self::computeLegacyJsHash($last8);
        }

        $signatures[] = self::computeLegacyJsHash($digits);

        if (! empty($salt)) {
            $signatures[] = hash_hmac('sha256', $digits, $salt);
            $signatures[] = hash('sha256', $salt.$digits);
            if (strlen($digits) >= 8) {
                $last8 = substr($digits, -8);
                $signatures[] = hash_hmac('sha256', $last8, $salt);
                $signatures[] = hash('sha256', $salt.$last8);
            }
        }

        return array_values(array_unique(array_filter($signatures)));
    }

    /**
     * Match a batch of phone numbers or fingerprints against active, non-blocked BANGO users.
     *
     * @param  array<string>  $inputs
     * @return array{suggestions: array<array<string, mixed>>, scanned: int, matched_count: int}
     *
     * @throws ValidationException
     */
    public function match(User $currentUser, array $inputs): array
    {
        $rawCount = count($inputs);

        if ($rawCount > self::MAX_BATCH_SIZE) {
            throw ValidationException::withMessages([
                'fingerprints' => [
                    'Le carnet d\'adresses dépasse la limite autorisée de '.self::MAX_BATCH_SIZE.' entrées.',
                ],
            ]);
        }

        if (empty($inputs)) {
            return [
                'suggestions' => [],
                'scanned' => 0,
                'matched_count' => 0,
            ];
        }

        $salt = config('services.contact_matching.salt');

        // Build target lookup set
        $targetLookup = [];
        $scannedCount = 0;

        foreach ($inputs as $entry) {
            if (! is_string($entry) && ! is_numeric($entry)) {
                continue;
            }

            $val = trim((string) $entry);
            if ($val === '') {
                continue;
            }

            $scannedCount++;
            $targetLookup[$val] = true;
            $targetLookup[strtolower($val)] = true;

            // If entry is a raw phone number, generate its signatures as well
            if (preg_match('/[0-9+]/', $val) && strlen($val) <= 25) {
                $sigs = self::generateSignaturesForPhone($val, $salt);
                foreach ($sigs as $sig) {
                    $targetLookup[$sig] = true;
                    $targetLookup[strtolower($sig)] = true;
                }
            }
        }

        // Query eligible candidates from database
        $candidates = User::query()
            ->where('status', 'active')
            ->where('id', '!=', $currentUser->id)
            ->whereNotNull('phone')
            ->where(function (Builder $query) {
                $query->whereNull('suspended_until')
                    ->orWhere('suspended_until', '<=', now());
            })
            // Mutual block exclusions (neither blocker nor blocked)
            ->whereNotExists(function ($sub) use ($currentUser) {
                $sub->selectRaw(1)
                    ->from('user_blocks')
                    ->where(function ($b) use ($currentUser) {
                        $b->where('user_blocks.blocker_id', $currentUser->id)
                            ->whereColumn('user_blocks.blocked_id', 'users.id');
                    })
                    ->orWhere(function ($b) use ($currentUser) {
                        $b->whereColumn('user_blocks.blocker_id', 'users.id')
                            ->where('user_blocks.blocked_id', $currentUser->id);
                    });
            })
            ->get();

        $matchedUsers = [];

        /** @var User $user */
        foreach ($candidates as $user) {
            $userPhone = (string) $user->phone;
            if ($userPhone === '') {
                continue;
            }

            $userSigs = self::generateSignaturesForPhone($userPhone, $salt);
            $hasMatch = false;

            foreach ($userSigs as $sig) {
                if (isset($targetLookup[$sig]) || isset($targetLookup[strtolower($sig)])) {
                    $hasMatch = true;
                    break;
                }
            }

            if ($hasMatch) {
                $matchedUsers[] = $this->formatSuggestion($user);
            }
        }

        return [
            'suggestions' => $matchedUsers,
            'scanned' => $scannedCount,
            'matched_count' => count($matchedUsers),
        ];
    }

    /**
     * Format privacy-safe suggestion profile.
     *
     * Never reveals the phone number, email, or submitted fingerprint.
     *
     * @return array<string, mixed>
     */
    protected function formatSuggestion(User $user): array
    {
        $place = null;
        if (! empty($user->neighbourhood) && ! empty($user->city)) {
            $place = "{$user->neighbourhood}, {$user->city}";
        } elseif (! empty($user->city)) {
            $place = $user->city;
        }

        return [
            'id' => (string) $user->id,
            'name' => $user->name,
            'display_name' => $user->display_name ?? $user->name,
            'handle' => $user->handle,
            'avatar_url' => $user->avatar_url,
            'is_verified' => (bool) $user->is_verified,
            'from_contacts' => true,
            'meta' => $place ?? '',
            'place' => $place,
        ];
    }
}
