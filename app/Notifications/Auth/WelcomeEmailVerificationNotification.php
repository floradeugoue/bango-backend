<?php

namespace App\Notifications\Auth;

use App\Models\Auth\Otp;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;

class WelcomeEmailVerificationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public string $code;

    public function __construct(?string $code = null)
    {
        $this->code = $code ?: (string) random_int(100000, 999999);
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // Enregistrer ou mettre à jour le code dans la table des OTPs (contexte email_verification)
        Otp::updateOrCreate(
            [
                'identifier' => $notifiable->email,
                'channel' => 'email',
                'context' => 'email_verification',
            ],
            [
                'code' => Hash::make($this->code),
                'attempts' => 0,
                'expires_at' => Carbon::now()->addHours(24),
                'locked_until' => null,
            ]
        );

        $verificationUrl = URL::temporarySignedRoute(
            'api.auth.email.verify.link',
            Carbon::now()->addHours(24),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]
        );

        $locale = strtolower((string) ($notifiable->locale ?? 'fr'));
        $name = $notifiable->display_name ?: ($notifiable->name ?: 'Utilisateur');

        if ($locale === 'en') {
            return (new MailMessage)
                ->subject('Welcome to BANGO! Confirm your email address')
                ->greeting("Hello {$name},")
                ->line('Welcome to BANGO, your community and real estate app!')
                ->line('Please confirm your email address to secure your account and recover your access at any time.')
                ->action('Confirm my email address', $verificationUrl)
                ->line("Or enter this 6-digit confirmation code in the application: **{$this->code}**")
                ->line('This confirmation link and code are valid for 24 hours.')
                ->line('If you did not create an account on BANGO, you can safely ignore this email.');
        }

        return (new MailMessage)
            ->subject('Bienvenue sur BANGO ! Confirmez votre adresse e-mail')
            ->greeting("Bonjour {$name},")
            ->line('Bienvenue sur BANGO, votre application communautaire et immobilière !')
            ->line('Merci de confirmer votre adresse e-mail pour sécuriser votre compte et faciliter la récupération de vos accès en toute sécurité.')
            ->action('Confirmer mon adresse e-mail', $verificationUrl)
            ->line("Ou entrez ce code de confirmation à 6 chiffres dans l'application : **{$this->code}**")
            ->line('Ce lien et ce code de confirmation sont valables pendant 24 heures.')
            ->line("Si vous n'avez pas créé de compte sur BANGO, vous pouvez ignorer cet e-mail en toute tranquillité.");
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'welcome_email_verification',
            'email' => $notifiable->email,
            'code' => $this->code,
        ];
    }
}

