<?php

namespace App\Enums\Visit;

enum VisitSlotStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    public function isAvailable(): bool
    {
        return $this === self::Open;
    }
}
