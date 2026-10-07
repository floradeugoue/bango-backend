<?php

namespace App\Enums;

enum HandleVerdict: string
{
    case Idle = 'idle';
    case Taken = 'taken';
    case Reserved = 'reserved';
    case TooShort = 'too-short';
    case TooLong = 'too-long';
    case BadShape = 'bad-shape';
    case Free = 'free';
}
