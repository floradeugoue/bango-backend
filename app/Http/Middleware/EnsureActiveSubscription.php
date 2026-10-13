<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Subscription\BadgeResolutionService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveSubscription
{
    public function __construct(
        protected BadgeResolutionService $badgeService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  string  $badge  landlord|agent
     */
    public function handle(Request $request, Closure $next, string $badge): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // 1. Check if user has an active or in-grace subscription for this badge
        if (! $user->hasActiveSubscription($badge)) {
            return response()->json([
                'code' => 'SUBSCRIPTION_REQUIRED',
                'message' => "Un abonnement {$badge} actif est requis pour effectuer cette action.",
                'required_badge' => $badge,
            ], 403);
        }

        // 2. Check if KYC is approved and not lapsed
        if (! $user->is_verified) {
            return response()->json([
                'code' => 'KYC_REQUIRED',
                'message' => 'La validation de votre identité (KYC) est requise pour débloquer cette fonctionnalité.',
                'required_badge' => $badge,
            ], 403);
        }

        if ($user->verification_lapsed) {
            return response()->json([
                'code' => 'KYC_LAPSED',
                'message' => 'Votre pièce d\'identité a expiré. Veuillez mettre à jour vos documents KYC pour réactiver cette action.',
                'required_badge' => $badge,
            ], 403);
        }

        return $next($request);
    }
}
