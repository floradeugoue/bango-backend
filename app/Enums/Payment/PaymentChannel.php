<?php

namespace App\Enums\Payment;

enum PaymentChannel: string
{
    case MobileMoney = 'mobile_money';
    case AppleIap = 'apple_iap';
    case GooglePlay = 'google_play';
    case CreditWallet = 'credit_wallet';
    case Card = 'card';

    public function label(): string
    {
        return match ($this) {
            self::MobileMoney => 'Mobile Money (Orange / MTN)',
            self::AppleIap => 'Apple In-App Purchase',
            self::GooglePlay => 'Google Play Billing',
            self::CreditWallet => 'Portefeuille Crédit BANGO',
            self::Card => 'Carte Bancaire',
        };
    }
}
