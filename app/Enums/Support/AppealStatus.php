<?php

namespace App\Enums\Support;

enum AppealStatus: string
{
    case Pending = 'pending';
    case UnderReview = 'under_review';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Recours en attente',
            self::UnderReview => 'En cours d\'instruction',
            self::Approved => 'Recours accepté',
            self::Rejected => 'Recours rejeté',
        };
    }
}
