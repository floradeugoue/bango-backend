<?php

namespace App\Http\Controllers\Admin\Kyc;

use App\Http\Controllers\Controller;
use App\Models\Kyc\KycSubmission;
use App\Models\Support\ModerationAppeal;
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
        $filters = $request->only([
            'badge',
            'city',
            'cadence',
            'sort',
            'expiring_soon',
            'verification_lapsed',
            'q',
            'status',
            'has_badge',
        ]);
        $perPage = $request->integer('per_page', 20);

        $users = $this->kycService->getBadges($filters, $perPage);

        $items = collect($users->items())->map(function (User $user) {
            $latestSub = $user->subscriptions->first();

            $diffDays = null;
            $isExpiringSoon = false;
            if ($latestSub && $latestSub->current_period_end) {
                $diffDays = (int) abs($latestSub->current_period_end->diffInDays(now()));
                $isExpiringSoon = $latestSub->current_period_end->isFuture() && $diffDays <= 7;
            }

            $hasBadge = ! empty($user->badge) && $user->badge !== 'none';

            return [
                'user_id' => $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name ?? $user->name,
                'avatar_url' => $user->avatar_url,
                'avatarUrl' => $user->avatar_url,
                'city' => $user->city,
                'email' => $user->email,
                'phone' => $user->phone,
                'badge' => $user->badge,
                'has_badge' => $hasBadge ? 1 : 0,
                'is_verified' => (bool) $user->is_verified,
                'verification_lapsed' => (bool) $user->verification_lapsed,
                'subscription' => $latestSub ? [
                    'id' => $latestSub->id,
                    'badge_type' => $latestSub->badge_type,
                    'cadence' => $latestSub->cadence,
                    'state' => $latestSub->state,
                    'current_period_start' => $latestSub->current_period_start?->toISOString(),
                    'current_period_end' => $latestSub->current_period_end?->toISOString(),
                    'days_remaining' => $diffDays,
                    'is_expiring_soon' => $isExpiringSoon,
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
        $filters = $request->only([
            'q',
            'appeal_status',
            'badge',
            'reason',
        ]);
        $perPage = $request->integer('per_page', 20);

        $refusals = $this->kycService->getRefusals($filters, $perPage);

        $items = collect($refusals->items())->map(function (KycSubmission $sub) {
            $decidedAt = $sub->decided_at ?? $sub->updated_at;
            $canAppealUntil = $decidedAt ? $decidedAt->copy()->addDays(30) : null;
            $isAppealActive = $canAppealUntil ? now()->lt($canAppealUntil) : false;

            // Recherche du recours lié (ModerationAppeal ou KycSubmission de type appeal)
            $modAppeal = ModerationAppeal::where('user_id', $sub->user_id)
                ->where(function ($q) use ($sub) {
                    $q->where('sanction_reference', (string) $sub->id)
                        ->orWhere('sanction_reference', (string) $sub->reference)
                        ->orWhere('sanction_type', 'kyc_refusal');
                })
                ->latest()
                ->first();

            $kycAppeal = null;
            if (! $modAppeal) {
                $kycAppeal = KycSubmission::where('user_id', $sub->user_id)
                    ->where('id', '!=', $sub->id)
                    ->where('created_at', '>=', $sub->decided_at ?? $sub->created_at)
                    ->where(function ($q) use ($sub) {
                        $q->where('metadata->kind', 'appeal')
                            ->orWhere('metadata->refused_submission_id', (string) $sub->id);
                    })
                    ->latest()
                    ->first();
            }

            $appealData = null;
            $appealStatus = 'open';

            if ($modAppeal) {
                $statusVal = $modAppeal->status instanceof \BackedEnum ? $modAppeal->status->value : (string) $modAppeal->status;
                $appealData = [
                    'id' => (string) $modAppeal->id,
                    'reference' => $modAppeal->reference,
                    'status' => $statusVal,
                    'reason' => $modAppeal->reason ?? $modAppeal->explanation,
                    'created_at' => $modAppeal->created_at?->toISOString(),
                ];
                $appealStatus = in_array($statusVal, ['pending', 'in_review'], true) ? 'appealed' : 'closed';
            } elseif ($kycAppeal) {
                $statusVal = $kycAppeal->status instanceof \BackedEnum ? $kycAppeal->status->value : (string) $kycAppeal->status;
                $appealData = [
                    'id' => (string) $kycAppeal->id,
                    'reference' => $kycAppeal->reference,
                    'status' => $statusVal,
                    'reason' => $kycAppeal->rejection_reason ?? 'Recours KYC',
                    'created_at' => $kycAppeal->created_at?->toISOString(),
                ];
                $appealStatus = in_array($statusVal, ['pending', 'supplement_requested'], true) ? 'appealed' : 'closed';
            } else {
                $appealStatus = $isAppealActive ? 'open' : 'closed';
            }

            return [
                'id' => $sub->id,
                'reference' => $sub->reference,
                'account' => [
                    'id' => (string) $sub->user->id,
                    'name' => $sub->user->name,
                    'display_name' => $sub->user->display_name ?? $sub->user->name,
                    'avatar_url' => $sub->user->avatar_url,
                    'city' => $sub->user->city,
                    'badge' => $sub->user->badge,
                ],
                'user' => [
                    'id' => $sub->user->id,
                    'name' => $sub->user->name,
                    'display_name' => $sub->user->display_name ?? $sub->user->name,
                    'avatar_url' => $sub->user->avatar_url,
                    'city' => $sub->user->city,
                    'email' => $sub->user->email,
                    'phone' => $sub->user->phone,
                    'badge' => $sub->user->badge,
                ],
                'profile_category' => $sub->profile_category,
                'badge' => $sub->profile_category,
                'rejection_reason' => $sub->rejection_reason,
                'decided_at' => $decidedAt?->toISOString(),
                'decided_by' => $sub->decidedBy ? [
                    'id' => $sub->decidedBy->id,
                    'name' => $sub->decidedBy->name,
                ] : null,
                'appeal_window_days' => 30,
                'can_appeal_until' => $canAppealUntil?->toISOString(),
                'is_appeal_active' => $isAppealActive,
                'appeal_status' => $appealStatus,
                'appeal' => $appealData,
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
