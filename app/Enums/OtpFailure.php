<?php

namespace App\Enums;

enum OtpFailure: string
{
    case Invalid = 'invalid';
    case Expired = 'expired';
    case Locked = 'locked';
    case NumberTaken = 'number-taken';
}
