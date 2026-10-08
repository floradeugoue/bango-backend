<?php

namespace App\Services\Auth;

use Resend\Laravel\Facades\Resend;
use Illuminate\Support\Facades\Log;
use App\Models\User;
use App\Models\ResetPasswordToken;
use Illuminate\Support\Str;
use Exception;

class ResetPasswordService
{
    /**
     * Generate a reset token and send it via email.
     */
    public function sendResetLink(User $user): void
    {
        // 1. Generate unique secure token
        $token = Str::random(64);

        // 2. Save token to DB
        ResetPasswordToken::create([
            'user_id' => $user->id,
            'token' => $token, // Stored plain text as we lookup by it and it's a one-time link.
            'expires_at' => now()->addMinutes(60),
            'used_at' => null,
        ]);

        // 3. Create deep link (React Native compatible)
        // e.g. bango://reset-password?token=XYZ
        // If there's an app url in config, use it. Otherwise use generic structure.
        $appUrl = config('app.url');
        $resetLink = rtrim($appUrl, '/') . "/reset-password?token={$token}";

        // 4. Send email via Resend
        try {
            $fromAddress = config('mail.from.address');
            $fromName = config('mail.from.name');

            $result = Resend::emails()->send([
                'from' => "{$fromName} <{$fromAddress}>",
                'to' => [$user->email],
                'subject' => 'Réinitialisation de votre mot de passe BANGO',
                'html' => view('emails.reset_password', ['resetLink' => $resetLink])->render(),
                'text' => "Bonjour,\n\nVous avez demandé la réinitialisation de votre mot de passe BANGO. Utilisez le lien suivant :\n\n{$resetLink}\n\nCe lien expire dans 60 minutes.",
            ]);

            Log::info('Reset password email sent', ['user_id' => $user->id, 'resend_id' => $result->id]);
        } catch (Exception $e) {
            Log::error('Reset password email send failed', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Impossible d\'envoyer l\'email de réinitialisation.');
        }
    }
}
