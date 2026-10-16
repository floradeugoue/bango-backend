<?php

namespace App\Notifications\Boost;

use App\Models\Boost\Campaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CampaignActivatedNotification extends Notification implements ShouldQueue
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
            ->subject('Votre campagne de boost est active')
            ->line('Votre campagne publicitaire a été activée et est désormais diffusée dans les feeds.')
            ->action('Voir la campagne', url("/boost/campaigns/{$this->campaign->id}"));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'campaign_activated',
            'campaign_id' => $this->campaign->id,
            'page_id' => $this->campaign->page_id,
            'budget' => (float) $this->campaign->budget_amount,
            'objective' => $this->campaign->objective->value,
            'message' => 'Votre campagne de boost est désormais active et en cours de diffusion.',
        ];
    }
}
