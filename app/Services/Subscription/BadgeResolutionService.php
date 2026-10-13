<?php

namespace App\Services\Subscription;

use App\Models\Subscription\BadgeHistory;
use App\Models\Subscription\Subscription;
use App\Models\User;

class BadgeResolutionService
{
    /**
     * Official BANGO visual token colors.
     */
    public const COLOR_LANDLORD = '#1E9E5A'; // Vert Bailleur

    public const COLOR_AGENT = '#E69900';    // Orange Agent

    public const COLOR_DOUBLE = '#D80F0F';   // Rouge Signature BANGO (Double badge)

    /**
     * Resolve the active badges and visual states of a user.
     *
     * @return array{
     *   badge: string,
     *   badges: array<string>,
     *   ringColor: ?string,
     *   isVerified: bool,
     *   verificationLapsed: bool,
     *   hasLandlord: bool,
     *   hasAgent: bool,
     *   inGrace: bool
     * }
     */
    public function resolveUserBadges(User $user): array
    {
        // 1. Fetch usable subscriptions (active or grace period)
        $usableSubscriptions = $user->subscriptions()
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('state', 'active')
                        ->where('current_period_end', '>', now());
                })->orWhere(function ($q) {
                    $q->where('state', 'grace')
                        ->where('grace_until', '>', now());
                });
            })
            ->get();

        $hasLandlordSub = $usableSubscriptions->contains(fn (Subscription $sub) => $sub->badge_type === 'landlord');
        $hasAgentSub = $usableSubscriptions->contains(fn (Subscription $sub) => $sub->badge_type === 'agent');

        $inGrace = $usableSubscriptions->contains(fn (Subscription $sub) => $sub->state === 'grace');

        // 2. KYC verification rule:
        // A badge requires KYC verification (is_verified = true).
        // If verification_lapsed is true, active badge privileges are suspended.
        $isKycApproved = (bool) $user->is_verified;
        $verificationLapsed = (bool) ($user->verification_lapsed ?? false);

        $hasLandlordBadge = $hasLandlordSub && $isKycApproved && ! $verificationLapsed;
        $hasAgentBadge = $hasAgentSub && $isKycApproved && ! $verificationLapsed;

        // 3. Determine the primary badge and ring color
        $badge = 'none';
        $badges = [];
        $ringColor = null;

        if ($hasLandlordBadge && $hasAgentBadge) {
            $badge = 'double';
            $badges = ['Bailleur', 'Agent'];
            $ringColor = self::COLOR_DOUBLE;
        } elseif ($hasLandlordBadge) {
            $badge = 'landlord';
            $badges = ['Bailleur'];
            $ringColor = self::COLOR_LANDLORD;
        } elseif ($hasAgentBadge) {
            $badge = 'agent';
            $badges = ['Agent'];
            $ringColor = self::COLOR_AGENT;
        }

        $isVerified = ($badge !== 'none');

        return [
            'badge' => $badge,
            'badges' => $badges,
            'ringColor' => $ringColor,
            'isVerified' => $isVerified,
            'verificationLapsed' => $verificationLapsed,
            'hasLandlord' => $hasLandlordBadge,
            'hasAgent' => $hasAgentBadge,
            'inGrace' => $inGrace,
        ];
    }

    /**
     * Synchronize and persist the user's cached badge column and history.
     */
    public function syncUserBadge(User $user, string $source = 'subscription', ?User $actor = null, ?string $reason = null): array
    {
        $status = $this->resolveUserBadges($user);
        $oldBadge = $user->badge ?? 'none';
        $newBadge = $status['badge'];

        if ($oldBadge !== $newBadge) {
            $user->badge = $newBadge;
            $user->save();

            BadgeHistory::create([
                'user_id' => $user->id,
                'badge' => $newBadge,
                'event' => $newBadge === 'none' ? 'revoked' : 'activated',
                'source' => $source,
                'actor_id' => $actor?->id,
                'reason' => $reason ?? "Transition de {$oldBadge} vers {$newBadge}",
                'metadata' => [
                    'old_badge' => $oldBadge,
                    'new_badge' => $newBadge,
                    'ring_color' => $status['ringColor'],
                    'in_grace' => $status['inGrace'],
                ],
            ]);
        }

        return $status;
    }
}
