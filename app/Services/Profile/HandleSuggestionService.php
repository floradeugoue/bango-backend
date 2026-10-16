<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Support\Str;

class HandleSuggestionService
{
    public const RESERVED = [
        'admin',
        'administrator',
        'root',
        'bango',
        'support',
        'moderation',
        'moderator',
        'official',
        'officiel',
        'cceda',
        'help',
        'system',
    ];

    /**
     * Generate 4 to 5 unique, verified-free handle suggestions based on user identity and location.
     *
     * @return array<string>
     */
    public function suggest(?string $firstName, ?string $lastName, ?string $city = null, ?string $fullName = null): array
    {
        // 1. Resolve first and last names if full name provided
        if ((empty($firstName) && empty($lastName)) && ! empty($fullName)) {
            $parts = preg_split('/\s+/', trim($fullName)) ?: [];
            $firstName = $parts[0] ?? null;
            $lastName = count($parts) > 1 ? implode('', array_slice($parts, 1)) : null;
        }

        $cleanFirst = $this->cleanPart($firstName);
        $cleanLast = $this->cleanPart($lastName);
        $cleanCity = $this->cleanPart($city);

        // Fallback base if input names are entirely missing or empty
        if (empty($cleanFirst) && empty($cleanLast)) {
            $cleanFirst = 'user';
        }

        $year = date('y');

        // 2. Generate candidate variants in order of natural elegance
        $candidates = [];

        if (! empty($cleanFirst) && ! empty($cleanLast)) {
            $candidates[] = "{$cleanFirst}.{$cleanLast}";
            if (! empty($cleanCity)) {
                $candidates[] = "{$cleanFirst}.{$cleanCity}";
            }
            $candidates[] = "{$cleanFirst}{$cleanLast}";
            $candidates[] = substr($cleanFirst, 0, 1).".{$cleanLast}";
            $candidates[] = "{$cleanFirst}.".substr($cleanLast, 0, 1);
            $candidates[] = "{$cleanFirst}.{$cleanLast}{$year}";
            if (! empty($cleanCity)) {
                $candidates[] = "{$cleanFirst}.{$cleanLast}.{$cleanCity}";
                $candidates[] = "{$cleanFirst}{$cleanCity}";
            }
        } elseif (! empty($cleanFirst)) {
            $candidates[] = "{$cleanFirst}";
            if (! empty($cleanCity)) {
                $candidates[] = "{$cleanFirst}.{$cleanCity}";
                $candidates[] = "{$cleanFirst}{$cleanCity}";
            }
            $candidates[] = "{$cleanFirst}.{$year}";
            $candidates[] = "{$cleanFirst}{$year}";
        }

        // 3. Filter, validate, and verify availability against real DB
        $suggestions = [];
        $base = ! empty($cleanFirst) ? $cleanFirst : 'user';

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCandidate($candidate);
            if ($this->isValidAndAvailable($normalized, $suggestions)) {
                $suggestions[] = $normalized;
                if (count($suggestions) >= 5) {
                    return $suggestions;
                }
            }
        }

        // 4. Fill remaining slots up to 4 or 5 with numbered variants if collisions occurred
        $attempts = 0;
        while (count($suggestions) < 5 && $attempts < 30) {
            $attempts++;
            $rand = rand(10, 999);
            $suffix = (string) $rand;

            if (! empty($cleanCity) && $attempts % 2 === 0) {
                $candidate = "{$base}.{$cleanCity}{$rand}";
            } else {
                $candidate = "{$base}.{$suffix}";
            }

            $normalized = $this->normalizeCandidate($candidate);
            if ($this->isValidAndAvailable($normalized, $suggestions)) {
                $suggestions[] = $normalized;
            }
        }

        return array_values(array_slice($suggestions, 0, 5));
    }

    /**
     * Clean and normalize a string part (transliterate accents, lowercase, remove non-alnum).
     */
    protected function cleanPart(?string $part): string
    {
        if ($part === null) {
            return '';
        }

        $transliterated = Str::ascii(trim($part));
        $lowercased = strtolower($transliterated);

        return preg_replace('/[^a-z0-9]/', '', $lowercased) ?? '';
    }

    /**
     * Normalize a candidate handle: truncate to max 20, remove trailing dots, lowercase.
     */
    protected function normalizeCandidate(string $candidate): string
    {
        $truncated = substr(strtolower(trim($candidate, '.')), 0, 20);

        return rtrim($truncated, '.');
    }

    /**
     * Check if candidate satisfies length, shape, reserved words and database uniqueness.
     *
     * @param  array<string>  $currentList
     */
    protected function isValidAndAvailable(string $candidate, array $currentList): bool
    {
        if (in_array($candidate, $currentList, true)) {
            return false;
        }

        if (strlen($candidate) < 3 || strlen($candidate) > 20) {
            return false;
        }

        if (! preg_match('/^[a-z0-9.]+$/', $candidate)) {
            return false;
        }

        if (str_starts_with($candidate, '.') || str_ends_with($candidate, '.') || str_contains($candidate, '..')) {
            return false;
        }

        // Check reserved words
        $stripped = str_replace('.', '', $candidate);
        foreach (self::RESERVED as $reservedWord) {
            if ($stripped === $reservedWord || str_starts_with($stripped, $reservedWord)) {
                return false;
            }
        }

        // Check real DB availability
        return ! User::where('handle', $candidate)->exists();
    }
}
