<?php

namespace App\Enums\Square;

enum PostAudience: string
{
    case Public = 'public';
    case Followers = 'followers';
    case Neighbourhood = 'neighbourhood';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
