<?php

namespace App\Enums\Story;

enum StoryReplyType: string
{
    case Text = 'text';
    case Reaction = 'reaction';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
