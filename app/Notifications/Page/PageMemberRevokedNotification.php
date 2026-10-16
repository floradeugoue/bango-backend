<?php

namespace App\Notifications\Page;

use App\Models\Page\PageMember;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PageMemberRevokedNotification extends Notification implements ShouldQueue
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
        $pageName = $this->member->page->name ?? 'la page';

        return (new MailMessage)
            ->subject("Révocation d'accès à la page {$pageName}")
            ->line("Vos accès et droits de gestion sur la page {$pageName} ont été révoqués.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'page_member_revoked',
            'page_id' => $this->member->page_id,
            'page_name' => $this->member->page->name ?? 'Page',
            'member_id' => $this->member->id,
            'message' => "Vos droits sur la Page Professionnelle ont été révoqués avec prise d'effet immédiate.",
        ];
    }
}
