<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Profile\ContactMatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
     * Protected by strict rate-limiting (10 requests/min/user) to prevent brute-force phone enumeration.
     */
    public function match(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rateLimitKey = 'contact_matching:'.($user?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 10)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'kind' => 'rate_limited',
                'message' => 'Trop de requêtes de rapprochement de contacts. Veuillez réessayer plus tard.',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $request->validate([
            'fingerprints' => ['nullable', 'array', 'max:500'],
            'fingerprints.*' => ['string', 'max:128'],
            'phones' => ['nullable', 'array', 'max:500'],
            'phones.*' => ['string', 'max:30'],
            'numbers' => ['nullable', 'array', 'max:500'],
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

        return response()->json([
            'state' => 'matched',
            'data' => $result['suggestions'],
            'suggestions' => $result['suggestions'],
            'scanned' => $result['scanned'],
            'matched_count' => $result['matched_count'],
        ], 200);
    }
}
