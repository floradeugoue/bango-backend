<?php

namespace App\Services\Message;

use App\Enums\Message\MessageType;
use App\Models\Message\Conversation;
use App\Models\Message\Message;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VoiceMessageService
{
    public const MAX_VOICE_SIZE_KB = 25600; // 25 MB

    public function __construct(
        protected MessageService $messageService
    ) {}

    /**
     * Traite l'upload d'une note vocale et crée le message correspondant.
     *
     * @param  array<string, mixed>  $options
     */
    public function storeVoiceNote(
        Conversation $conversation,
        User $sender,
        UploadedFile $audioFile,
        array $options = []
    ): Message {
        $mime = $audioFile->getMimeType() ?: $audioFile->getClientMimeType();

        // 1. Validation du type MIME
        if (! in_array($mime, MediaStorageService::ALLOWED_AUDIO_MIMES, true)) {
            throw ValidationException::withMessages([
                'voice' => ['Le format audio n\'est pas pris en charge. Formats acceptés : mp3, m4a, ogg, wav, aac.'],
            ]);
        }

        // 2. Validation de la taille maximale
        $sizeBytes = $audioFile->getSize();
        if ($sizeBytes > (self::MAX_VOICE_SIZE_KB * 1024)) {
            throw ValidationException::withMessages([
                'voice' => ['La taille de la note vocale dépasse la limite autorisée de 25 Mo.'],
            ]);
        }

        $disk = config('media.disk', 'public');
        $extension = $audioFile->getClientOriginalExtension() ?: 'm4a';
        $filename = Str::uuid().'.'.$extension;
        $directory = 'messages/voices/'.$conversation->id;

        // 3. Stockage du fichier
        $path = $audioFile->storeAs($directory, $filename, $disk);
        if (! $path) {
            throw new Exception('Échec de l\'enregistrement de la note vocale sur le stockage.');
        }

        $durationMs = isset($options['duration_ms']) ? (int) $options['duration_ms'] : null;

        try {
            // 4. Création du message via MessageService
            return $this->messageService->sendMessage($conversation, $sender, [
                'type' => MessageType::Voice,
                'client_message_id' => $options['client_message_id'] ?? null,
                'reply_to_message_id' => $options['reply_to_message_id'] ?? null,
                'attachment_path' => $path,
                'attachment_mime' => $mime,
                'attachment_size' => $sizeBytes,
                'attachment_duration_ms' => $durationMs,
                'metadata' => [
                    'duration_ms' => $durationMs,
                    'original_name' => $audioFile->getClientOriginalName(),
                ],
            ]);
        } catch (Exception $e) {
            // Nettoyage du fichier orphelin en cas d'échec
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }

            throw $e;
        }
    }
}
