<?php

namespace App\Notifications\Admin;

use App\Models\Admin\AdminAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AdminAccessRequestPendingNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public AdminAccessRequest $accessRequest
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $applicantName = trim("{$this->accessRequest->first_name} {$this->accessRequest->last_name}") ?: $this->accessRequest->email;
        $ref = $this->accessRequest->reference ?: "ACC-{$this->accessRequest->id}";
        $backofficeUrl = rtrim((string) env('BACKOFFICE_URL', config('services.backoffice.url', 'https://admin.bango.app')), '/');
        $reviewUrl = "{$backofficeUrl}/team";

        $roleSlug = $this->accessRequest->requested_role;
        $roleTitle = match ($roleSlug) {
            'direction' => 'Direction',
            'moderation' => 'Modérateur',
            'support' => 'Support',
            'finance' => 'Finance',
            'kyc' => 'Agent KYC',
            default => ucfirst((string) $roleSlug),
        };

        return (new MailMessage)
            ->subject("[BANGO Back-office] Nouvelle demande d'accès reçue — {$ref}")
            ->greeting("Bonjour {$notifiable->name},")
            ->line("Une nouvelle demande d'accès au portail d'administration a été soumise.")
            ->line("Référence du dossier : **{$ref}**")
            ->line("Collaborateur : **{$applicantName}** ({$this->accessRequest->email})")
            ->line("Département : {$this->accessRequest->department}")
            ->line("Rôle sollicité : **{$roleTitle}**")
            ->line("Motif invoqué : {$this->accessRequest->reason}")
            ->line('Délai d\'engagement de réponse : 48 h ouvrées.')
            ->action('Examiner la demande dans le back-office', $reviewUrl);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $ref = $this->accessRequest->reference ?: "ACC-{$this->accessRequest->id}";
        $applicantName = trim("{$this->accessRequest->first_name} {$this->accessRequest->last_name}") ?: $this->accessRequest->email;

        return [
            'type' => 'admin_access_request_pending',
            'access_request_id' => $this->accessRequest->id,
            'reference' => $ref,
            'applicant_name' => $applicantName,
            'applicant_email' => $this->accessRequest->email,
            'department' => $this->accessRequest->department,
            'requested_role' => $this->accessRequest->requested_role,
            'reason' => $this->accessRequest->reason,
            'message' => "Nouvelle demande d'accès back-office reçue ({$ref}) de {$applicantName} pour le rôle {$this->accessRequest->requested_role}.",
        ];
    }
}
