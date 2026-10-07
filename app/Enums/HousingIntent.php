<?php

namespace App\Enums;

enum HousingIntent: string
{
    case Active = 'active';
    case Browsing = 'browsing';
    case No = 'no';
}
