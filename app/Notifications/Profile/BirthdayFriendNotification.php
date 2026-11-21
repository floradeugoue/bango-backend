<?php

namespace App\Notifications\Profile;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdayFriendNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public User $birthdayUser,
        public ?string $conversationId = null
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->birthdayUser->display_name ?: $this->birthdayUser->name;

        return (new MailMessage)
            ->subject("C'est l'anniversaire de {$name} aujourd'hui !")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("C'est l'anniversaire de {$name} aujourd'hui. Souhaite-le-lui !")
            ->action('Lui envoyer un message', $this->conversationId ? url("/messages/threads/{$this->conversationId}") : url("/messages/user/{$this->birthdayUser->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $name = $this->birthdayUser->display_name ?: $this->birthdayUser->name;

        return [
            'type' => 'birthday_friend',
            'user_id' => $this->birthdayUser->id,
            'conversation_id' => $this->conversationId,
            'title' => trans('notifications.birthday_friend.title', [], $locale),
            'message' => trans('notifications.birthday_friend.body', ['name' => $name], $locale),
            'action_url' => $this->conversationId ? "/messages/threads/{$this->conversationId}" : "/messages/user/{$this->birthdayUser->id}",
        ];
    }
}
