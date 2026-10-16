<?php

namespace App\Notifications\Support;

use App\Models\Support\SupportTicket;
use App\Models\Support\SupportTicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SupportTicketReplyNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SupportTicket $ticket,
        public SupportTicketMessage $ticketMessage
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $senderName = $this->ticketMessage->sender?->name ?? 'Support BANGO';
        $preview = mb_substr($this->ticketMessage->message, 0, 100);

        return [
            'type' => 'support_ticket_reply',
            'ticket_id' => $this->ticket->id,
            'ticket_reference' => $this->ticket->reference,
            'subject' => $this->ticket->subject,
            'message_id' => $this->ticketMessage->id,
            'sender_id' => $this->ticketMessage->sender_id,
            'sender_name' => $senderName,
            'preview' => $preview,
            'title' => "Réponse au ticket {$this->ticket->reference}",
            'created_at' => $this->ticketMessage->created_at?->toIso8601String(),
        ];
    }
}
