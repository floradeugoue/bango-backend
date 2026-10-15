<?php

namespace App\Enums\Story;

enum StoryStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Archived = 'archived';
    case Deleted = 'deleted';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
