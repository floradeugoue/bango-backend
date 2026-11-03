<?php

namespace App\Mail\Admin;

use App\Models\Admin\AdminInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AdminInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $roleTitle;

    public string $roleTitleEn;

    public string $expiresAtFormattedFr;

    public string $expiresAtFormattedEn;

    public function __construct(
        public AdminInvitation $invitation,
        public string $activationUrl
    ) {
        $this->roleTitle = match ($this->invitation->role) {
            'direction' => 'Direction',
            'moderation' => 'Modérateur',
            'support' => 'Support',
            'finance' => 'Finance',
            'kyc' => 'Agent KYC',
            default => ucfirst((string) $this->invitation->role),
        };

        $this->roleTitleEn = match ($this->invitation->role) {
            'direction' => 'Management / Executive',
            'moderation' => 'Moderator',
            'support' => 'Support Agent',
            'finance' => 'Finance Officer',
            'kyc' => 'KYC Compliance Agent',
            default => ucfirst((string) $this->invitation->role),
        };

        $this->expiresAtFormattedFr = $this->invitation->expires_at
            ? $this->invitation->expires_at->format('d/m/Y à H:i').' (UTC)'
            : '3 jours';

        $this->expiresAtFormattedEn = $this->invitation->expires_at
            ? $this->invitation->expires_at->format('F j, Y at g:i A').' (UTC)'
            : '3 days';
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invitation à rejoindre le back-office BANGO / Invitation to join BANGO Back-office',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.admin.invitation',
            text: 'emails.admin.invitation_text',
            with: [
                'invitation' => $this->invitation,
                'activationUrl' => $this->activationUrl,
                'roleTitle' => $this->roleTitle,
                'roleTitleEn' => $this->roleTitleEn,
                'expiresAtFormattedFr' => $this->expiresAtFormattedFr,
                'expiresAtFormattedEn' => $this->expiresAtFormattedEn,
            ],
        );
    }
}
