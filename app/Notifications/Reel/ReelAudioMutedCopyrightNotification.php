<?php

namespace App\Notifications\Reel;

use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Notifications\Concerns\RespectsNotificationPreferences;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ReelAudioMutedCopyrightNotification extends Notification implements ShouldQueue
{
    use Queueable, RespectsNotificationPreferences;

    public function __construct(
        public Reel $reel,
        public Sound|string $sound,
        public ?string $reason = null
    ) {}

    public function toMail(object $notifiable): MailMessage
    {
        $soundId = $this->sound instanceof Sound ? $this->sound->id : (string) $this->sound;

        return (new MailMessage)
            ->subject('Son retiré sur l\'un de vos reels — BANGO')
            ->greeting("Bonjour {$notifiable->name},")
            ->line('Le son original utilisé dans l\'un de vos reels a été retiré de la plateforme suite à une atteinte aux droits d\'auteur.')
            ->line('Votre vidéo reste publiée et accessible, mais sa piste audio a été coupée.')
            ->when($this->reason, fn ($mail) => $mail->line("Motif / Précisions : {$this->reason}"))
            ->line('Vous pouvez à tout moment modifier votre publication ou lui associer un nouveau son depuis l\'application BANGO.')
            ->action('Ouvrir l\'application', url('/'));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $locale = $notifiable->locale ?? 'fr';
        $soundId = $this->sound instanceof Sound ? $this->sound->id : (string) $this->sound;

        $message = $locale === 'en'
            ? 'The original audio on your reel was muted because the sound was removed due to a copyright infringement.'
            : 'Le son de votre reel a été coupé car le son original a été retiré suite à une atteinte aux droits d\'auteur.';

        return [
            'type' => 'reel_audio_muted_copyright',
            'reel_id' => (string) $this->reel->id,
            'sound_id' => $soundId,
            'reason' => $this->reason,
            'message' => $message,
        ];
    }
}
