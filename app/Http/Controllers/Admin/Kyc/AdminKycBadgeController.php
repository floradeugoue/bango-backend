<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Kyc\KycSubmission;
use App\Models\User;
use App\Services\Admin\AdminKycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminKycBadgeController extends Controller
{
    public function __construct(
        protected AdminKycService $kycService
    ) {}

    /**
     * A.2. Surveillance des badges professionnels.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['badge', 'expiring_soon', 'verification_lapsed', 'q', 'status']);
        $perPage = $request->integer('per_page', 20);

        $users = $this->kycService->getBadges($filters, $perPage);

        $items = collect($users->items())->map(function (User $user) {
            $latestSub = $user->subscriptions->first();

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'badge' => $user->badge,
                'is_verified' => (bool) $user->is_verified,
                'verification_lapsed' => (bool) $user->verification_lapsed,
                'subscription' => $latestSub ? [
                    'id' => $latestSub->id,
                    'badge_type' => $latestSub->badge_type,
                    'state' => $latestSub->state,
                    'current_period_start' => $latestSub->current_period_start?->toISOString(),
                    'current_period_end' => $latestSub->current_period_end?->toISOString(),
                    'is_expiring_soon' => $latestSub->current_period_end && $latestSub->current_period_end->diffInDays(now()) <= 7,
                ] : null,
                'created_at' => $user->created_at->toISOString(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    public function badges(Request $request): JsonResponse
    {
        return $this->index($request);
    }

    /**
     * A.3. Dossiers refusés avec historique utile et délai de recours de 30 jours.
     */
    public function refusals(Request $request): JsonResponse
    {
        $filters = $request->only(['q']);
        $perPage = $request->integer('per_page', 20);

        $refusals = $this->kycService->getRefusals($filters, $perPage);

        $items = collect($refusals->items())->map(function (KycSubmission $sub) {
            $decidedAt = $sub->decided_at ?? $sub->updated_at;
            $canAppealUntil = $decidedAt ? $decidedAt->copy()->addDays(30) : null;
            $isAppealActive = $canAppealUntil ? now()->lt($canAppealUntil) : false;

            return [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                ],
                'profile_category' => $sub->profile_category,
                'rejection_reason' => $sub->rejection_reason,
                'decided_at' => $decidedAt?->toISOString(),
                'decided_by' => $sub->decidedBy ? [
                    'id' => $sub->decidedBy->id,
                    'name' => $sub->decidedBy->name,
                ] : null,
                'appeal_window_days' => 30,
                'can_appeal_until' => $canAppealUntil?->toISOString(),
                'is_appeal_active' => $isAppealActive,
                'can_resubmit' => true,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $items,
            'meta' => [
                'current_page' => $refusals->currentPage(),
                'per_page' => $refusals->perPage(),
                'total' => $refusals->total(),
                'last_page' => $refusals->lastPage(),
            ],
        ]);
    }
}
