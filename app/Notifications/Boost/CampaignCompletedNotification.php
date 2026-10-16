<?php

namespace App\Notifications\Boost;

use App\Models\Boost\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Campaign $campaign
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
            ->subject('Votre campagne de boost est terminée')
            ->line('Votre campagne publicitaire est arrivée à son terme. Consultez votre rapport de performance.')
            ->action('Voir les résultats', url("/boost/campaigns/{$this->campaign->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign_completed',
            'campaign_id' => $this->campaign->id,
            'page_id' => $this->campaign->page_id,
            'impressions_count' => $this->campaign->impressions_count,
            'clicks_count' => $this->campaign->clicks_count,
            'reach_count' => $this->campaign->reach_count,
            'spent' => (float) $this->campaign->spent_amount,
            'message' => 'Votre campagne de boost est terminée. Consultez vos statistiques.',
        ];
    }
}
