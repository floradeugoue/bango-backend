<?php

namespace App\Enums\Story;

enum StoryMediaType: string
{
    case Image = 'image';
    case Video = 'video';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
