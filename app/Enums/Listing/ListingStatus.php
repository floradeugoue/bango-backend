<?php

namespace App\Enums\Listing;

enum ListingStatus: string
{
    case Draft = 'draft';
    case Review = 'review';
    case Published = 'published';
    case Reserved = 'reserved';
    case Taken = 'taken';
    case Rejected = 'rejected';

    /**
     * Determine if this status is publicly visible on the public feed and map.
     */
    public function isPubliclyVisible(): bool
    {
        return in_array($this, [self::Published, self::Reserved], true);
    }

    /**
     * Determine if this status is choosable directly by a landlord in the UI.
     */
    public function isChoosableByOwner(): bool
    {
        return in_array($this, [self::Draft, self::Published, self::Reserved, self::Taken], true);
    }

    /**
     * Validate whether a status transition is permitted.
     */
    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::Draft => in_array($target, [self::Review, self::Published], true),
            self::Review => in_array($target, [self::Published, self::Rejected, self::Draft], true),
            self::Published => in_array($target, [self::Reserved, self::Taken, self::Draft], true),
            self::Reserved => in_array($target, [self::Published, self::Taken, self::Draft], true),
            self::Taken => in_array($target, [self::Published, self::Draft], true),
            self::Rejected => in_array($target, [self::Draft, self::Review], true),
        };
    }
}
