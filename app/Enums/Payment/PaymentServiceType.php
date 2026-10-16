<?php

namespace App\Enums\Payment;

enum PaymentServiceType: string
{
    case Subscription = 'subscription';
    case Certification = 'certification';
    case Boost = 'boost';
    case Visit = 'visit';
    case Credit = 'credit';

    public function label(): string
    {
        return match ($this) {
            self::Subscription => 'Abonnement & Badge',
            self::Certification => 'Certification Page Pro',
            self::Boost => 'Boost Annonce',
            self::Visit => 'Frais de Visite Immobilière',
            self::Credit => 'Achat de Crédits BANGO',
        };
    }
}
