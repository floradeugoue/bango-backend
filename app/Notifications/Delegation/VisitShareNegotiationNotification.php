<?php

namespace App\Notifications\Delegation;

use App\Models\Delegation\VisitShare;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class VisitShareNegotiationNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public VisitShare $visitShare,
        public string $event, // 'offer' | 'agreed'
        public ?int $pageId = null,
        public array $extra = []
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $offerBy = $this->visitShare->offer_by;
        $percent = $this->event === 'agreed'
            ? $this->visitShare->agreed_percent
            : $this->visitShare->offer_percent;

        $offerByLabel = $offerBy === 'landlord'
            ? ($locale === 'en' ? 'landlord' : 'le bailleur')
            : ($locale === 'en' ? 'agent' : "l'agent");

        $title = $this->event === 'agreed'
            ? trans('notifications.visit_share_agreed.title', ['percent' => $percent], $locale)
            : trans('notifications.visit_share_offer.title', ['percent' => $percent], $locale);

        $message = $this->event === 'agreed'
            ? trans('notifications.visit_share_agreed.body', ['percent' => $percent], $locale)
            : trans('notifications.visit_share_offer.body', ['percent' => $percent, 'offer_by' => $offerByLabel], $locale);

        return array_merge([
            'type' => 'visit_share_'.$this->event,
            'category' => 'immo',
            'page_id' => $this->pageId,
            'landlord_id' => $this->visitShare->landlord_id,
            'agent_id' => $this->visitShare->agent_id,
            'offer_percent' => (float) $this->visitShare->offer_percent,
            'agreed_percent' => $this->visitShare->agreed_percent !== null ? (float) $this->visitShare->agreed_percent : null,
            'offer_by' => $this->visitShare->offer_by,
            'title' => $title,
            'message' => $message,
        ], $this->extra);
    }
}
