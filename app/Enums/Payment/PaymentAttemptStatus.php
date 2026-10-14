<?php

namespace App\Enums\Payment;

enum PaymentAttemptStatus: string
{
    case Initiated = 'initiated';
    case Pending = 'pending';
    case Successful = 'successful';
    case Failed = 'failed';
    case Timeout = 'timeout';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Initiated => 'Initié',
            self::Pending => 'En attente de confirmation',
            self::Successful => 'Succès',
            self::Failed => 'Échec',
            self::Timeout => 'Délai d’attente dépassé (120s)',
            self::Cancelled => 'Annulé par l’utilisateur',
        };
    }
}
