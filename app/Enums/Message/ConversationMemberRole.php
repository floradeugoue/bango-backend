<?php

namespace App\Enums\Message;

enum ConversationMemberRole: string
{
    case Member = 'member';
    case Admin = 'admin';
}
