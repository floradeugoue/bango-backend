<?php

namespace App\Enums\Square;

enum PostCommentsBy: string
{
    case Everyone = 'everyone';
    case Followers = 'followers';
    case Nobody = 'nobody';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
