<?php

use App\Models\Message\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('chat.conversation.{conversationId}', function (User $user, string $conversationId): bool {
    /** @var Conversation|null $conversation */
    $conversation = Conversation::query()->find($conversationId);

    if (! $conversation) {
        return false;
    }

    return $conversation->isMember($user->id);
});

Broadcast::channel('chat.user.{userId}', function (User $user, int|string $userId): bool {
    return (int) $user->id === (int) $userId;
});
