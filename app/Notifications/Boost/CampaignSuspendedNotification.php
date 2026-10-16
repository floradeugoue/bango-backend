<?php

namespace App\Notifications\Boost;

use App\Models\Boost\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Campaign $campaign,
        public string $reason
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
        return (new MailMessage)
            ->subject('Votre campagne de boost a été suspendue')
            ->line("Votre campagne publicitaire a été suspendue pour le motif suivant : {$this->reason}.")
            ->action('Gérer mes campagnes', url("/boost/campaigns/{$this->campaign->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign_suspended',
            'campaign_id' => $this->campaign->id,
            'page_id' => $this->campaign->page_id,
            'reason' => $this->reason,
            'message' => "Votre campagne de boost a été suspendue : {$this->reason}.",
        ];
    }
}
