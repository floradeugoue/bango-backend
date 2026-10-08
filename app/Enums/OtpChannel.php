<?php

namespace App\Enums;

enum OtpChannel: string
{
    case Sms = 'sms';
    case Email = 'email';
}
