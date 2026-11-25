<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Profile\ContactMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class ContactMatchingController extends Controller
{
    public function __construct(
        protected ContactMatchingService $matchingService
    ) {}

    /**
     * Reconcile encrypted address book fingerprints against BANGO users (AU-19).
     *
     * Strict privacy & anti-enumeration guarantees:
     * - Zero-retention: fingerprints and address book numbers are processed in-memory and immediately forgotten (never logged, cached, or persisted).
     * - Strictly limited to 3 comparisons per account per day.
     * - Maximum batch size of 2,000 entries per request.
     */
    public function match(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $userId = $user?->id ?? $request->ip();
        $rateLimitKey = 'contact_matching:daily:'.$userId;

        // Limite stricte : 3 comparaisons par compte et par jour (86 400 s) pour empêcher l'énumération numéro par numéro
        if (RateLimiter::tooManyAttempts($rateLimitKey, 3)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'kind' => 'rate_limited',
                'message' => 'Limite de 3 comparaisons de contacts par jour atteinte. Veuillez réessayer demain.',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 86400);

        $request->validate([
            'fingerprints' => ['nullable', 'array', 'max:2000'],
            'fingerprints.*' => ['string', 'max:128'],
            'phones' => ['nullable', 'array', 'max:2000'],
            'phones.*' => ['string', 'max:30'],
            'numbers' => ['nullable', 'array', 'max:2000'],
            'numbers.*' => ['string', 'max:30'],
        ]);

        $fingerprints = $request->input('fingerprints', []);
        $phones = $request->input('phones', $request->input('numbers', []));

        $inputs = array_merge((array) $fingerprints, (array) $phones);

        if (count($inputs) > ContactMatchingService::MAX_BATCH_SIZE) {
            return response()->json([
                'message' => 'Le carnet d\'adresses dépasse la limite autorisée de '.ContactMatchingService::MAX_BATCH_SIZE.' entrées.',
                'errors' => [
                    'fingerprints' => [
                        'Le carnet d\'adresses dépasse la limite autorisée de '.ContactMatchingService::MAX_BATCH_SIZE.' entrées.',
                    ],
                ],
            ], 422);
        }

        try {
            $result = $this->matchingService->match($user, $inputs);
        } catch (ValidationException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422);
        }

        if ($user) {
            $matchedIds = collect($result['suggestions'])
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn ($id) => $id > 0)
                ->all();

            if (! empty($matchedIds)) {
                $cachedKey = 'user_contact_matches:'.$user->id;
                $existing = (array) Cache::get($cachedKey, []);
                $merged = array_values(array_unique(array_merge($existing, $matchedIds)));
                Cache::put($cachedKey, $merged, now()->addDays(30));
            }
        }

        return response()->json([
            'state' => 'matched',
            'data' => $result['suggestions'],
            'suggestions' => $result['suggestions'],
            'scanned' => $result['scanned'],
            'matched_count' => $result['matched_count'],
        ], 200);
    }
}
