<?php

namespace App\Enums\Delegation;

enum DelegationStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Declined = 'declined';
    case Revoked = 'revoked';
    case Expired = 'expired';

    public function isOperational(): bool
    {
        return $this === self::Active;
    }
}
