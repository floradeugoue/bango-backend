<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TranslationService
{
    /**
     * Common African / Wolof stopwords and keywords for fast local detection
     */
    protected const WOLOF_KEYWORDS = [
        'nanga', 'def', 'jërëjëf', 'jerejef', 'waaw', 'déedéet', 'deedeet', 'asmaan',
        'baax', 'baaxna', 'bango', 'yàlla', 'yalla', 'ndax', 'amul', 'amna', 'nit',
        'sama', 'sa', 'sunu', 'séen', 'seen', 'ñoom', 'noom', 'man', 'yaw', 'moom',
        'lan', 'fan', 'ban', 'kañ', 'kan', 'nu', 'ngi', 'ak', 'te', 'ndaxte', 'ndegem',
        'bëgg', 'begg', 'wax', 'dëgg', 'degg', 'xam', 'dellu', 'dem', 'ñëw', 'new',
        'neex', 'rafet', 'teranga', 'toubab', 'almadies', 'dakar', 'senegal',
    ];

    /**
     * Detect the language of a given text.
     * Returns an ISO 639-1 code (e.g. 'fr', 'en', 'wo', 'es', etc.)
     */
    public function detectLanguage(?string $text): string
    {
        if (empty($text) || trim($text) === '') {
            return 'fr';
        }

        $cleanText = trim($text);

        // 1. Fast heuristic: Wolof check
        if ($this->isWolof($cleanText)) {
            return 'wo';
        }

        // 2. If Google Translate API Key is configured, query detect endpoint
        $googleKey = config('services.translation.google_api_key');
        if (! empty($googleKey)) {
            try {
                $response = Http::timeout(3)->post("https://translation.googleapis.com/language/translate/v2/detect?key={$googleKey}", [
                    'q' => mb_substr($cleanText, 0, 500),
                ]);

                if ($response->successful()) {
                    $detected = $response->json('data.detections.0.0.language');
                    if (! empty($detected)) {
                        return strtolower($detected);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Google Translate detect error: '.$e->getMessage());
            }
        }

        // 3. Fallback heuristic detection for fr / en
        return $this->detectLocalLanguage($cleanText);
    }

    /**
     * Translate text to target language ('fr' or 'en') with persistent caching per (content, target).
     *
     * @return array{text: string, source_language: string}
     */
    public function translate(string $text, string $target = 'fr'): array
    {
        $text = trim($text);
        if ($text === '') {
            return [
                'text' => '',
                'source_language' => $target,
            ];
        }

        $target = strtolower($target);
        if (! in_array($target, ['fr', 'en'], true)) {
            $target = 'fr';
        }

        // Cache key based on SHA256 of text + target language
        $cacheKey = 'translation:'.hash('sha256', $text).':'.$target;

        return Cache::rememberForever($cacheKey, function () use ($text, $target) {
            $detectedSource = $this->detectLanguage($text);

            // If detected source already matches target, return original text directly
            if ($detectedSource === $target) {
                return [
                    'text' => $text,
                    'source_language' => $detectedSource,
                ];
            }

            $driver = config('services.translation.driver', 'google');
            $deeplKey = config('services.translation.deepl_api_key');
            $googleKey = config('services.translation.google_api_key');

            if ($driver === 'deepl' && ! empty($deeplKey)) {
                $result = $this->translateWithDeepL($text, $target, $deeplKey);
                if ($result !== null) {
                    return $result;
                }
            }

            if (! empty($googleKey)) {
                $result = $this->translateWithGoogle($text, $target, $googleKey);
                if ($result !== null) {
                    return $result;
                }
            }

            // Fallback: if no translation API is configured or request failed
            return [
                'text' => $text,
                'source_language' => $detectedSource,
            ];
        });
    }

    /**
     * Translate using Google Cloud Translation API v2
     *
     * @return array{text: string, source_language: string}|null
     */
    protected function translateWithGoogle(string $text, string $target, string $apiKey): ?array
    {
        try {
            $response = Http::timeout(5)->post("https://translation.googleapis.com/language/translate/v2?key={$apiKey}", [
                'q' => $text,
                'target' => $target,
                'format' => 'text',
            ]);

            if ($response->successful()) {
                $data = $response->json('data.translations.0');
                if ($data && isset($data['translatedText'])) {
                    return [
                        'text' => html_entity_decode($data['translatedText'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                        'source_language' => strtolower($data['detectedSourceLanguage'] ?? 'fr'),
                    ];
                }
            } else {
                Log::warning('Google Translate failed: '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('Google Translation exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Translate using DeepL API
     *
     * @return array{text: string, source_language: string}|null
     */
    protected function translateWithDeepL(string $text, string $target, string $apiKey): ?array
    {
        try {
            $isFreePlan = str_ends_with($apiKey, ':fx');
            $endpoint = $isFreePlan
                ? 'https://api-free.deepl.com/v2/translate'
                : 'https://api.deepl.com/v2/translate';

            $targetLangCode = strtoupper($target === 'en' ? 'EN-US' : 'FR');

            $response = Http::withHeaders([
                'Authorization' => "DeepL-Auth-Key {$apiKey}",
            ])->timeout(5)->post($endpoint, [
                'text' => [$text],
                'target_lang' => $targetLangCode,
            ]);

            if ($response->successful()) {
                $translation = $response->json('translations.0');
                if ($translation && isset($translation['text'])) {
                    return [
                        'text' => $translation['text'],
                        'source_language' => strtolower($translation['detected_source_language'] ?? 'fr'),
                    ];
                }
            } else {
                Log::warning('DeepL Translate failed: '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error('DeepL Translation exception: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Check if text contains predominant Wolof words
     */
    protected function isWolof(string $text): bool
    {
        $words = preg_split('/[\s,\.\?!:;\-\(\)]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return false;
        }

        $matches = 0;
        foreach ($words as $word) {
            if (in_array($word, self::WOLOF_KEYWORDS, true)) {
                $matches++;
            }
        }

        return ($matches / count($words)) >= 0.25 || $matches >= 2;
    }

    /**
     * Local heuristic between French and English
     */
    protected function detectLocalLanguage(string $text): string
    {
        $normalized = mb_strtolower($text);

        // French-specific accented letters
        if (preg_match('/[éèêëàâùûôîïçœæ]/u', $normalized)) {
            return 'fr';
        }

        // Common stopwords analysis
        $words = preg_split('/[\s,\.\?!:;\-\(\)]+/u', $normalized, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($words)) {
            return 'fr';
        }

        $enCommon = ['the', 'is', 'are', 'this', 'that', 'with', 'and', 'for', 'you', 'from', 'have', 'what', 'good', 'great', 'awesome', 'nice', 'check', 'out', 'my', 'your'];
        $frCommon = ['le', 'la', 'les', 'un', 'une', 'des', 'est', 'sont', 'avec', 'pour', 'dans', 'sur', 'ce', 'cette', 'mon', 'ma', 'mes', 'ton', 'ta', 'tes', 'qui', 'que', 'très', 'bien'];

        $enCount = 0;
        $frCount = 0;

        foreach ($words as $w) {
            if (in_array($w, $enCommon, true)) {
                $enCount++;
            }
            if (in_array($w, $frCommon, true)) {
                $frCount++;
            }
        }

        if ($enCount > $frCount) {
            return 'en';
        }

        return 'fr';
    }
}
