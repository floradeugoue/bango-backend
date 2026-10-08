<?php

namespace App\Services\Otp;

use Resend\Laravel\Facades\Resend;
use Illuminate\Support\Facades\Log;
use Exception;

class OtpEmailService
{
    /**
     * Envoie le code OTP par email via Resend.
     *
     * @param string $email L'adresse email de destination.
     * @param string $code Le code OTP à envoyer.
     * @return void
     * @throws Exception
     */
    public function send(string $email, string $code): void
    {
        try {
            $fromAddress = config('mail.from.address');
            $fromName = config('mail.from.name');

            $result = Resend::emails()->send([
                'from' => "{$fromName} <{$fromAddress}>",
                'to' => [$email],
                'subject' => 'Votre code de vérification BANGO',
                'html' => view('emails.otp', ['code' => $code])->render(),
                'text' => "Bonjour,\n\nVoici votre code de vérification BANGO :\n\n{$code}\n\nCe code est valable pendant 10 minutes.\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez simplement cet email.\n\nL'équipe BANGO",
            ]);

            Log::info('OTP email sent', ['email' => $email, 'resend_id' => $result->id]);
        } catch (Exception $e) {
            // Logger l'erreur en interne sans exposer la clé API ou les détails internes au client
            Log::error('OTP email send failed', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw new Exception('Impossible d\'envoyer l\'email OTP.');
        }
    }
}
