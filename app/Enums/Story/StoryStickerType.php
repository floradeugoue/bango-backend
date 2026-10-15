<?php

namespace App\Enums\Story;

enum StoryStickerType: string
{
    case Text = 'text';
    case Poll = 'poll';
    case Question = 'question';
    case Location = 'location';
    case Mention = 'mention';
    case Countdown = 'countdown';
    case Housing = 'housing';
    case Music = 'music';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
