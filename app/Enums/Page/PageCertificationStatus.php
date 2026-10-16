<?php

namespace App\Enums\Page;

enum PageCertificationStatus: string
{
    case None = 'none';
    case Pending = 'pending';
    case SupplementRequested = 'supplement_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    public function label(): string
    {
        return match ($this) {
            self::None => 'Non certifiée',
            self::Pending => 'Dossier en cours d\'examen',
            self::SupplementRequested => 'Pièces complémentaires demandées',
            self::Approved => 'Certifiée officielle',
            self::Rejected => 'Dossier rejeté',
            self::Suspended => 'Certification suspendue',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function isOfficial(): bool
    {
        return $this === self::Approved;
    }
}
