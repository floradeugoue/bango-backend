<?php

namespace App\Enums\Square;

enum PostMediaKind: string
{
    case Photo = 'photo';
    case Video = 'video';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
