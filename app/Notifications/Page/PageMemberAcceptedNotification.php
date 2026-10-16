<?php

namespace App\Notifications\Page;

use App\Models\Page\PageMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageMemberAcceptedNotification extends Notification implements ShouldQueue
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
        $userName = $this->member->user->name ?? 'Un membre';
        $pageName = $this->member->page->name ?? 'votre page';

        return (new MailMessage)
            ->subject("Invitation acceptée pour {$pageName}")
            ->line("{$userName} a accepté votre invitation et rejoint l'équipe de {$pageName}.")
            ->action('Gérer les membres', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_member_accepted',
            'page_id' => $this->member->page_id,
            'page_name' => $this->member->page->name ?? 'Page',
            'user_id' => $this->member->user_id,
            'user_name' => $this->member->user->name ?? 'Utilisateur',
            'role' => $this->member->role->value,
            'message' => "Un utilisateur a accepté l'invitation à rejoindre la Page.",
        ];
    }
}
