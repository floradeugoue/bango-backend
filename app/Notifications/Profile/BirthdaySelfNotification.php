<?php

namespace App\Notifications\Profile;

use App\Models\User;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BirthdaySelfNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public User $user
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $firstName = $this->user->firstName();

        return (new MailMessage)
            ->subject("Joyeux anniversaire, {$firstName} ! — BANGO")
            ->greeting("Joyeux anniversaire {$firstName} !")
            ->line("Toute l'équipe BANGO te souhaite une belle journée pleine de réussite.")
            ->action('Ouvrir l\'application', url('/'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $firstName = $this->user->firstName();

        return [
            'type' => 'birthday_self',
            'user_id' => $this->user->id,
            'title' => trans('notifications.birthday_self.title', [], $locale),
            'message' => trans('notifications.birthday_self.body', ['name' => $firstName], $locale),
        ];
    }
}
