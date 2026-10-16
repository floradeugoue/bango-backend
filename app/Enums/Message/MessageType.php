<?php

namespace App\Enums\Message;

enum MessageType: string
{
    case Text = 'text';
    case Voice = 'voice';
    case Image = 'image';
    case Listing = 'listing';
    case StoryReply = 'story_reply';
    case System = 'system';
}
