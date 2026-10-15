<?php

namespace App\Enums\Visit;

enum VisitReservationStatus: string
{
    case PendingPayment = 'pending_payment';
    case Requested = 'requested';
    case Confirmed = 'confirmed';
    case Proposed = 'proposed';
    case Cancelled = 'cancelled';
    case Declined = 'declined';
    case Expired = 'expired';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';

    /**
     * Determine if this reservation currently holds/blocks a capacity slot.
     */
    public function holdsSlot(): bool
    {
        return in_array($this, [
            self::PendingPayment,
            self::Requested,
            self::Confirmed,
            self::Proposed,
        ], true);
    }

    /**
     * Determine if this reservation grants access to the listing's private address and instructions.
     */
    public function grantsPrivateAddressAccess(): bool
    {
        return $this === self::Confirmed;
    }

    /**
     * Determine if a transition to the target status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::PendingPayment => in_array($target, [self::Requested, self::Confirmed, self::Expired, self::Cancelled], true),
            self::Requested => in_array($target, [self::Confirmed, self::Proposed, self::Declined, self::Cancelled], true),
            self::Proposed => in_array($target, [self::Confirmed, self::Declined, self::Cancelled], true),
            self::Confirmed => in_array($target, [self::Cancelled, self::RefundPending, self::Refunded], true),
            self::Declined => in_array($target, [self::RefundPending, self::Refunded], true),
            self::Cancelled => in_array($target, [self::RefundPending, self::Refunded], true),
            self::RefundPending => in_array($target, [self::Refunded], true),
            self::Expired => false,
            self::Refunded => false,
        };
    }
}
