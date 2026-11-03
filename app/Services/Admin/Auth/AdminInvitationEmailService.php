<?php

namespace App\Services\Admin\Auth;

use App\Mail\Admin\AdminAccessRequestRejectedMail;
use App\Mail\Admin\AdminInvitationMail;
use App\Models\Admin\AdminAccessRequest;
use App\Models\Admin\AdminInvitation;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AdminInvitationEmailService
{
    /**
     * Envoie l'email d'invitation avec le lien d'activation au back-office.
     */
    public function sendInvitation(AdminInvitation $invitation, string $rawToken): void
    {
        try {
            $backofficeUrl = rtrim((string) env('BACKOFFICE_URL', config('services.backoffice.url', 'https://admin.bango.app')), '/');
            $activationUrl = "{$backofficeUrl}/login/activate?token={$rawToken}";

            Mail::to($invitation->email)->send(new AdminInvitationMail($invitation, $activationUrl));

            Log::info('Admin invitation email sent successfully', [
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'mailer' => config('mail.default'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send admin invitation email', [
                'email' => $invitation->email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envoie l'email informant le collaborateur du rejet de sa demande d'accès.
     */
    public function sendRejection(AdminAccessRequest $accessRequest, ?string $reason = null): void
    {
        try {
            Mail::to($accessRequest->email)->send(new AdminAccessRequestRejectedMail($accessRequest, $reason));

            Log::info('Admin access request rejection email sent', [
                'email' => $accessRequest->email,
                'role' => $accessRequest->requested_role,
                'mailer' => config('mail.default'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to send admin access request rejection email', [
                'email' => $accessRequest->email,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
