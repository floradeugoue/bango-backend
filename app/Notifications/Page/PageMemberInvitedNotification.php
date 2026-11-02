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
        $locale = $notifiable->locale ?? 'fr';
        $pageName = $this->member->page->name ?? 'Page';

        return [
            'type' => 'page_invitation',
            'member_id' => $this->member->id,
            'page_id' => $this->member->page_id,
            'page_name' => $pageName,
            'role' => $this->member->role->value,
            'role_label' => $this->member->role->label(),
            'invited_by_id' => $this->member->invited_by_id,
            'title' => trans('notifications.page_invitation.title', [], $locale),
            'message' => trans('notifications.page_invitation.body', ['page' => $pageName], $locale),
        ];
    }
}
