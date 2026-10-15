<?php

namespace App\Enums\Reel;

enum ReelReactionType: string
{
    case Like = 'like';
    case Dislike = 'dislike';

    /**
     * Obtenir toutes les valeurs sous forme de tableau.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
