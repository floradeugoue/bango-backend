<?php

namespace App\Enums\Support;

enum ReportStatus: string
{
    case Pending = 'pending';
    case Investigating = 'investigating';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Investigating => 'En cours d\'examen',
            self::Resolved => 'Traité avec sanction',
            self::Dismissed => 'Classé sans suite',
        };
    }
}
