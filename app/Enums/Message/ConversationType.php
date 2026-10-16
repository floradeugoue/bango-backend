<?php

namespace App\Enums\Message;

enum ConversationType: string
{
    case Direct = 'direct';
    case Group = 'group';
    case Page = 'page';
}
