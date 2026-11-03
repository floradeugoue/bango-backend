<?php

namespace App\Mail\Admin;

use App\Models\Admin\AdminAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminAccessRequestRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;

    public string $roleTitle;

    public string $roleTitleEn;

    public function __construct(
        public AdminAccessRequest $accessRequest,
        public ?string $reason = null
    ) {
        $this->name = trim("{$this->accessRequest->first_name} {$this->accessRequest->last_name}") ?: 'Collaborateur';

        $roleSlug = $this->accessRequest->requested_role;
        $this->roleTitle = match ($roleSlug) {
            'direction' => 'Direction',
            'moderation' => 'Modérateur',
            'support' => 'Support',
            'finance' => 'Finance',
            'kyc' => 'Agent KYC',
            default => ucfirst((string) $roleSlug),
        };

        $this->roleTitleEn = match ($roleSlug) {
            'direction' => 'Management / Executive',
            'moderation' => 'Moderator',
            'support' => 'Support Agent',
            'finance' => 'Finance Officer',
            'kyc' => 'KYC Compliance Agent',
            default => ucfirst((string) $roleSlug),
        };
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Mise à jour concernant votre demande d'accès BANGO / Update regarding your BANGO access request",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.access_request_rejected',
            text: 'emails.admin.access_request_rejected_text',
            with: [
                'name' => $this->name,
                'roleTitle' => $this->roleTitle,
                'roleTitleEn' => $this->roleTitleEn,
                'reason' => $this->reason,
            ],
        );
    }
}
