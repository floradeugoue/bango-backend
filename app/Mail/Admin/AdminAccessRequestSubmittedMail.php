<?php

namespace App\Mail\Admin;

use App\Models\Admin\AdminAccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminAccessRequestSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $name;

    public string $reference;

    public string $roleTitle;

    public string $roleTitleEn;

    public string $expectedDelayFr = '48 h ouvrées';

    public string $expectedDelayEn = '48 business hours';

    public function __construct(
        public AdminAccessRequest $accessRequest
    ) {
        $this->name = trim("{$this->accessRequest->first_name} {$this->accessRequest->last_name}") ?: 'Collaborateur';
        $this->reference = $this->accessRequest->reference ?: "ACC-{$this->accessRequest->id}";

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
            subject: "Accusé de réception : votre demande d'accès BANGO [{$this->reference}] / Acknowledgment: your BANGO access request [{$this->reference}]",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.access_request_submitted',
            text: 'emails.admin.access_request_submitted_text',
            with: [
                'accessRequest' => $this->accessRequest,
                'name' => $this->name,
                'reference' => $this->reference,
                'roleTitle' => $this->roleTitle,
                'roleTitleEn' => $this->roleTitleEn,
                'expectedDelayFr' => $this->expectedDelayFr,
                'expectedDelayEn' => $this->expectedDelayEn,
            ],
        );
    }
}

