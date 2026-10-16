<?php

namespace App\Notifications\Page;

use App\Models\Page\PageMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageMemberDeclinedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public PageMember $member
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $userName = $this->member->user->name ?? 'Un invité';
        $pageName = $this->member->page->name ?? 'votre page';

        return (new MailMessage)
            ->subject("Invitation déclinée pour {$pageName}")
            ->line("{$userName} a décliné l'invitation à rejoindre l'équipe de {$pageName}.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_member_declined',
            'page_id' => $this->member->page_id,
            'page_name' => $this->member->page->name ?? 'Page',
            'user_id' => $this->member->user_id,
            'user_name' => $this->member->user->name ?? 'Utilisateur',
            'message' => "L'invitation pour rejoindre la Page a été refusée.",
        ];
    }
}
