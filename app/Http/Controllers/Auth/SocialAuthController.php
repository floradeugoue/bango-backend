<?php

namespace App\Http\Controllers\Auth;

use App\Exceptions\Auth\SocialAuthException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SocialAuthRequest;
use App\Services\Auth\SocialAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;

class SocialAuthController extends Controller
{
    public function __construct(
        protected SocialAuthService $socialAuthService
    ) {}

    /**
     * Handle Google and Apple social sign-in, account creation, or explicit linking (AU).
     */
    public function __invoke(SocialAuthRequest $request): JsonResponse
    {
        $rateLimitKey = 'social_auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($rateLimitKey, 30)) {
            $seconds = RateLimiter::availableIn($rateLimitKey);

            return response()->json([
                'kind' => 'rate_limited',
                'message' => 'Trop de tentatives d\'authentification. Veuillez réessayer plus tard.',
                'retry_after' => $seconds,
            ], 429);
        }

        RateLimiter::hit($rateLimitKey, 60);

        try {
            // Retrieve authenticated user if Bearer token is present (account linking flow)
            $authUser = $request->user('api');

            $deviceName = $request->input('device_name')
                ?: $request->header('X-Device-Name')
                ?: 'Application mobile';

            $result = $this->socialAuthService->authenticate(
                provider: $request->validated('provider'),
                idToken: $request->validated('id_token'),
                expectedNonce: $request->validated('nonce'),
                clientName: $request->validated('name'),
                authUser: $authUser,
                deviceName: $deviceName,
                persistent: $request->boolean('persistent', true)
            );

            RateLimiter::clear($rateLimitKey);

            return response()->json($result['data'], $result['status']);
        } catch (SocialAuthException $e) {
            return response()->json([
                'kind' => $e->getKind(),
                'message' => $e->getMessage(),
            ], $e->getCode() >= 400 && $e->getCode() < 600 ? $e->getCode() : 422);
        }
    }
}
