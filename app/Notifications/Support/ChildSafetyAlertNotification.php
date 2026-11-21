<?php

namespace App\Notifications\Support;

use App\Models\Support\Report;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ChildSafetyAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Report $report
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
        return [
            'type' => 'child_safety_alert',
            'report_id' => $this->report->id,
            'reference' => $this->report->reference,
            'reportable_type' => $this->report->reportable_type,
            'reportable_id' => $this->report->reportable_id,
            'reason' => 'child_safety',
            'priority' => 'urgent',
            'title' => 'ALERTE CRITIQUE : Signalement Mineur en danger',
            'message' => "Un signalement pour protection des mineurs (réf. {$this->report->reference}) a été déposé et requiert une modération immédiate.",
            'details' => $this->report->details,
            'created_at' => $this->report->created_at?->toIso8601String(),
        ];
    }
}
