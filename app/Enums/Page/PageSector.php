<?php

namespace App\Enums\Page;

enum PageSector: string
{
    case RealEstateAgency = 'real_estate_agency';
    case Craftsman = 'craftsman';
    case Commerce = 'commerce';
    case Services = 'services';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::RealEstateAgency => 'Agence Immobilière',
            self::Craftsman => 'Artisan',
            self::Commerce => 'Commerce & Boutique',
            self::Services => 'Services & Conseil',
            self::Other => 'Autre secteur',
        };
    }

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
