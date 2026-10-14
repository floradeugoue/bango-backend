<?php

namespace App\Enums\Payment;

enum PaymentProvider: string
{
    case Monetbil = 'monetbil';
    case Flutterwave = 'flutterwave';
    case Apple = 'apple';
    case Google = 'google';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Monetbil => 'Monetbil (Mobile Money)',
            self::Flutterwave => 'Flutterwave',
            self::Apple => 'Apple In-App Purchase',
            self::Google => 'Google Play Billing',
            self::Credit => 'Crédit BANGO',
        };
    }
}
