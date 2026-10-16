<?php

namespace App\Notifications\Page;

use App\Models\Page\PageMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageMemberInvitedNotification extends Notification implements ShouldQueue
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
        $pageName = $this->member->page->name ?? 'une page';

        return (new MailMessage)
            ->subject("Invitation à rejoindre l'équipe de {$pageName}")
            ->line("Vous avez été invité à rejoindre la page {$pageName} avec le rôle {$this->member->role->label()}.")
            ->action('Voir mes invitations', url('/my-pages'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_member_invited',
            'page_id' => $this->member->page_id,
            'page_name' => $this->member->page->name ?? 'Page',
            'member_id' => $this->member->id,
            'role' => $this->member->role->value,
            'role_label' => $this->member->role->label(),
            'invited_by_id' => $this->member->invited_by_id,
            'message' => 'Vous avez reçu une invitation pour rejoindre une Page Professionnelle.',
        ];
    }
}
