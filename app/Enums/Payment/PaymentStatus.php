<?php

namespace App\Enums\Payment;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case RefundPending = 'refund_pending';
    case Refunded = 'refunded';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Processing => 'En cours de traitement',
            self::Confirmed => 'Confirmé',
            self::Failed => 'Échec',
            self::Cancelled => 'Annulé',
            self::RefundPending => 'Remboursement en cours',
            self::Refunded => 'Remboursé',
            self::Unknown => 'Indéterminé',
        };
    }

    /**
     * Determine if this status is terminal.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Failed, self::Cancelled, self::Refunded => true,
            default => false,
        };
    }

    /**
     * Check if a transition from the current status to target status is valid.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Pending => in_array($target, [
                self::Processing,
                self::Confirmed,
                self::Failed,
                self::Cancelled,
                self::Unknown,
            ], true),
            self::Processing => in_array($target, [
                self::Confirmed,
                self::Failed,
                self::Cancelled,
                self::Unknown,
            ], true),
            self::Unknown => in_array($target, [
                self::Processing,
                self::Confirmed,
                self::Failed,
                self::Cancelled,
            ], true),
            self::Confirmed => in_array($target, [
                self::RefundPending,
                self::Refunded,
            ], true),
            self::RefundPending => in_array($target, [
                self::Refunded,
                self::Confirmed,
            ], true),
            self::Failed, self::Cancelled, self::Refunded => false,
        };
    }
}
