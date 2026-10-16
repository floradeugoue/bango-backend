<?php

namespace App\Enums\Support;

enum TicketMessageSenderType: string
{
    case Client = 'client';
    case Support = 'support';
    case System = 'system';
    case InternalNote = 'internal_note';

    public function isInternal(): bool
    {
        return $this === self::InternalNote;
    }
}
