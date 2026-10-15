<?php

namespace App\Enums\Story;

enum StoryAudience: string
{
    case Everyone = 'everyone';
    case Followers = 'followers';
    case Chosen = 'chosen';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
