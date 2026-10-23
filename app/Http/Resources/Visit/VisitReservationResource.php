<?php

namespace App\Http\Resources\Visit;

use App\Enums\Visit\VisitDeclineReason;
use App\Models\Visit\VisitReservation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin VisitReservation
 */
class VisitReservationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $request->user();
        $isOwnerOrAgent = $viewer && ($viewer->id === $this->owner_id || ($this->agent_id && $viewer->id === $this->agent_id));
        $isAdmin = $viewer && $viewer->isAdmin();
        $isProspect = $viewer && $viewer->id === $this->prospect_id;
        $canAccessAddress = $isAdmin || $isOwnerOrAgent || ($isProspect && $this->grantsPrivateAddressAccess());

        $listing = $this->listing;
        $slot = $this->slot;
        $proposalSlot = $this->proposalSlot;

        return [
            'id' => $this->id,
            'listing_id' => $this->listing_id,
            'listing' => $listing ? [
                'id' => $listing->id,
                'title' => $listing->title,
                'mode' => $listing->mode,
                'price' => (float) $listing->price,
                'currency' => $listing->currency ?? 'XAF',
                'city' => $listing->city,
                'neighborhood' => $listing->neighbourhood ?? $listing->neighborhood,
                'neighbourhood' => $listing->neighbourhood ?? $listing->neighborhood,
                'address' => $canAccessAddress ? $listing->address : null,
                'cover_url' => $listing->cover_image_url,
                'cover_image_url' => $listing->cover_image_url,
            ] : null,
            'slot_id' => $this->slot_id,
            'slot' => $slot ? [
                'id' => $slot->id,
                'start_at' => $slot->start_at->toIso8601String(),
                'end_at' => $slot->end_at->toIso8601String(),
                'formatted_time' => $slot->start_at->translatedFormat('d M Y à H:i'),
            ] : null,
            'proposal_slot' => $proposalSlot ? [
                'id' => $proposalSlot->id,
                'start_at' => $proposalSlot->start_at->toIso8601String(),
                'end_at' => $proposalSlot->end_at->toIso8601String(),
                'formatted_time' => $proposalSlot->start_at->translatedFormat('d M Y à H:i'),
            ] : null,
            'status' => $this->status->value,
            'status_label' => match ($this->status->value) {
                'pending_payment' => 'Paiement en attente',
                'requested' => 'Demande envoyée',
                'confirmed' => 'Confirmée',
                'proposed' => 'Horaire alternatif proposé',
                'cancelled' => 'Annulée',
                'declined' => 'Déclinée',
                'expired' => 'Expirée',
                'refund_pending' => 'Remboursement en cours',
                'refunded' => 'Remboursée',
                default => $this->status->value,
            },
            'lock_expires_at' => $this->lock_expires_at?->toIso8601String(),
            'is_lock_active' => $this->isLockActive(),
            'fee_amount' => (float) $this->fee_amount,
            'currency' => $this->currency,
            'paid_at' => $this->paid_at?->toIso8601String(),
            'payment_reference' => $this->payment_reference,
            'prospect_message' => $this->prospect_message,
            'prospect' => [
                'id' => $this->prospect->id,
                'name' => $this->prospect->name,
                'avatar_url' => $this->prospect->avatar_url,
                'phone' => ($isOwnerOrAgent || $isAdmin) ? ($this->prospect_phone ?? $this->prospect->phone) : null,
            ],
            'cancellation_reason' => $this->cancellation_reason,
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'decline_reason' => $this->cancellation_reason ?? ($this->decline_reason instanceof VisitDeclineReason ? $this->decline_reason->value : $this->decline_reason),
            'refund_amount' => $this->refund_amount ? (float) $this->refund_amount : null,
            'refunded_at' => $this->refunded_at?->toIso8601String(),
            'refund_channel' => $this->refund_channel,
            'can_cancel' => ! in_array($this->status->value, ['cancelled', 'declined', 'expired', 'refunded'], true),
            'can_view_address' => $canAccessAddress,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
