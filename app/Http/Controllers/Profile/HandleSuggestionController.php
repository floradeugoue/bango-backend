<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Services\Profile\HandleSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class HandleSuggestionController extends Controller
{
    public function __construct(
        protected HandleSuggestionService $suggestionService
    ) {}

    /**
     * Propose 4 to 5 available, verified handles based on name, surname, and city (AU-15 / AU-16).
     */
    public function __invoke(Request $request): JsonResponse
    {
        $rateLimitKey = 'handle_suggestions:'.($request->user()?->id ?? $request->ip());

        if (RateLimiter::tooManyAttempts($rateLimitKey, 60)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'kind' => 'rate_limited',
                'message' => 'Trop de demandes de suggestions. Veuillez réessayer plus tard.',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        $request->validate([
            'first_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:100'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:50'],
        ]);

        $firstName = $request->input('first_name');
        $lastName = $request->input('last_name');
        $fullName = $request->input('name') ?: $request->input('display_name');
        $city = $request->input('city');

        $suggestions = $this->suggestionService->suggest(
            firstName: $firstName,
            lastName: $lastName,
            city: $city,
            fullName: $fullName
        );

        return response()->json([
            'data' => $suggestions,
        ], 200);
    }
}
