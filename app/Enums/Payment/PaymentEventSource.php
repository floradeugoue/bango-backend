<?php

namespace App\Enums\Payment;

enum PaymentEventSource: string
{
    case System = 'system';
    case Gateway = 'gateway';
    case Admin = 'admin';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::System => 'Système BANGO',
            self::Gateway => 'Passerelle de paiement',
            self::Admin => 'Administrateur',
            self::User => 'Utilisateur mobile',
        };
    }
}
