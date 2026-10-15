<?php

namespace App\Enums\Visit;

enum VisitDeclineReason: string
{
    case Unavailable = 'unavailable';
    case NoAnswer = 'no-answer';
    case SlotTaken = 'slot-taken';
    case PriceDisagreement = 'price_disagreement';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Unavailable => 'Bailleur indisponible',
            self::NoAnswer => 'Délai de réponse de 18 h dépassé',
            self::SlotTaken => 'Créneau déjà réservé',
            self::PriceDisagreement => 'Désaccord sur les conditions',
            self::Other => 'Autre motif',
        };
    }
}
