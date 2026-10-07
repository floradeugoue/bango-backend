<?php

namespace App\Enums;

enum Gender: string
{
    case Woman = 'woman';
    case Man = 'man';
    case Other = 'other';
    case Undisclosed = 'undisclosed';
}
