<?php

namespace App\Services\Upload;

use App\Exceptions\Upload\TusOffsetConflictException;
use App\Exceptions\Upload\TusUploadException;
use App\Models\Upload\TusUploadSession;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TusUploadService
{
    public const TUS_VERSION = '1.0.0';

    public const MAX_UPLOAD_SIZE = 524288000; // 500 Mo en octets

    public const SESSION_LIFETIME_HOURS = 24;

    public function __construct(
        protected MediaStorageService $mediaStorageService
    ) {}

    /**
     * Crée une nouvelle session de téléversement Tus.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function createSession(User $user, int $uploadLength, array $metadata = []): TusUploadSession
    {
        if ($uploadLength <= 0) {
            throw new TusUploadException('La taille du fichier (Upload-Length) doit être supérieure à zéro.', 400);
        }

        if ($uploadLength > self::MAX_UPLOAD_SIZE) {
            throw new TusUploadException(
                sprintf('Le fichier dépasse la taille maximale autorisée de %d Mo.', (int) (self::MAX_UPLOAD_SIZE / (1024 * 1024))),
                413,
                'FILE_TOO_LARGE'
            );
        }

        $sessionId = (string) Str::uuid();
        $disk = config('media.disk', 'public');

        // Récupération des informations depuis les métadonnées Tus
        $originalFilename = $metadata['filename'] ?? $metadata['name'] ?? null;
        $mimeType = $metadata['filetype'] ?? $metadata['mime_type'] ?? 'video/mp4';

        // Chemin temporaire sécurisé et isolé par utilisateur
        $tempDir = "uploads/temp/{$user->id}";
        $tempPath = "{$tempDir}/{$sessionId}.part";

        // S'assurer que le répertoire temporaire existe
        Storage::disk($disk)->makeDirectory($tempDir);

        // Initialisation du fichier temporaire vide
        Storage::disk($disk)->put($tempPath, '');

        return TusUploadSession::create([
            'id' => $sessionId,
            'user_id' => $user->id,
            'upload_length' => $uploadLength,
            'upload_offset' => 0,
            'mime_type' => $mimeType,
            'original_filename' => $originalFilename,
            'metadata' => $metadata,
            'status' => 'active',
            'temp_path' => $tempPath,
            'disk' => $disk,
            'expires_at' => Carbon::now()->addHours(self::SESSION_LIFETIME_HOURS),
        ]);
    }

    /**
     * Récupère l'offset confirmé actuel de la session.
     */
    public function getOffset(TusUploadSession $session): int
    {
        if ($session->isExpired()) {
            throw new TusUploadException('Cette session d\'upload a expiré.', 410, 'TUS_SESSION_EXPIRED');
        }

        if ($session->isCancelled()) {
            throw new TusUploadException('Cette session d\'upload a été annulée.', 410, 'TUS_SESSION_CANCELLED');
        }

        return (int) $session->upload_offset;
    }

    /**
     * Ajoute un fragment binaire au fichier temporaire à l'offset spécifié.
     */
    public function appendChunk(TusUploadSession $session, int $clientOffset, string $chunkData): TusUploadSession
    {
        return DB::transaction(function () use ($session, $clientOffset, $chunkData) {
            /** @var TusUploadSession $lockedSession */
            $lockedSession = TusUploadSession::where('id', $session->id)->lockForUpdate()->firstOrFail();

            if (! $lockedSession->isActive()) {
                if ($lockedSession->isCompleted()) {
                    throw new TusUploadException('Cette session d\'upload est déjà finalisée.', 400, 'TUS_SESSION_ALREADY_COMPLETED');
                }
                throw new TusUploadException('La session d\'upload n\'est plus active.', 410, 'TUS_SESSION_INACTIVE');
            }

            $currentOffset = (int) $lockedSession->upload_offset;

            // 1. Vérification stricte de l'offset
            if ($clientOffset !== $currentOffset) {
                throw new TusOffsetConflictException($currentOffset, $clientOffset);
            }

            $chunkSize = strlen($chunkData);

            if ($chunkSize === 0) {
                return $lockedSession;
            }

            // 2. Vérification de non-dépassement de la taille totale attendue
            if ($currentOffset + $chunkSize > $lockedSession->upload_length) {
                throw new TusUploadException(
                    'Le fragment envoyé dépasse la taille totale attendue du fichier.',
                    413,
                    'TUS_CHUNK_EXCEEDS_LENGTH'
                );
            }

            // 3. Écriture binaire directe en mode append
            $fullTempPath = Storage::disk($lockedSession->disk)->path($lockedSession->temp_path);
            $handle = fopen($fullTempPath, 'ab');

            if ($handle === false) {
                throw new TusUploadException('Impossible d\'ouvrir le fichier temporaire pour écriture.', 500);
            }

            $bytesWritten = fwrite($handle, $chunkData);
            fclose($handle);

            if ($bytesWritten !== $chunkSize) {
                throw new TusUploadException('Erreur d\'écriture disque lors de l\'enregistrement du fragment.', 500);
            }

            // 4. Mise à jour atomique de l'offset confirmé
            $newOffset = $currentOffset + $bytesWritten;
            $lockedSession->upload_offset = $newOffset;
            $lockedSession->updated_at = Carbon::now();

            // 5. Si la taille totale est atteinte, finaliser automatiquement le fichier
            if ($newOffset === (int) $lockedSession->upload_length) {
                $this->finalizeSession($lockedSession);
            } else {
                $lockedSession->save();
            }

            return $lockedSession;
        });
    }

    /**
     * Finalise le téléversement, valide le fichier complet et le promeut dans le stockage média permanent.
     *
     * @return array<string, mixed>
     */
    public function finalizeSession(TusUploadSession $session): array
    {
        if ($session->isCompleted() && ! empty($session->final_path)) {
            return [
                'id' => (string) $session->id,
                'path' => $session->final_path,
                'url' => $session->final_url ?? Storage::disk($session->disk)->url($session->final_path),
                'type' => str_starts_with($session->mime_type, 'image/') ? 'image' : 'video',
                'mime_type' => $session->mime_type,
                'file_size' => (int) $session->upload_length,
                'disk' => $session->disk,
            ];
        }

        $fullTempPath = Storage::disk($session->disk)->path($session->temp_path);

        if (! file_exists($fullTempPath)) {
            throw new TusUploadException('Le fichier temporaire d\'upload est introuvable.', 404);
        }

        $actualSize = filesize($fullTempPath);

        if ($actualSize !== (int) $session->upload_length) {
            throw new TusUploadException(
                sprintf('Taille de fichier incomplète : reçu %d octets sur %d attendus.', $actualSize, $session->upload_length),
                400,
                'INCOMPLETE_UPLOAD'
            );
        }

        // Vérification du type MIME réel
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $fullTempPath);
        finfo_close($finfo);

        $allowedMimes = array_merge(
            MediaStorageService::ALLOWED_VIDEO_MIMES,
            MediaStorageService::ALLOWED_IMAGE_MIMES
        );

        if (! in_array($detectedMime, $allowedMimes, true)) {
            // Nettoyage de sécurité en cas de type frauduleux
            Storage::disk($session->disk)->delete($session->temp_path);
            $session->status = 'cancelled';
            $session->save();

            throw new TusUploadException(
                "Format de fichier non autorisé ({$detectedMime}). Formats acceptés : MP4, MOV, WEBM, JPEG, PNG, WEBP.",
                415,
                'INVALID_MIME_TYPE'
            );
        }

        // Détermination du dossier permanent selon la destination spécifiée
        $metadata = $session->metadata ?? [];
        $kind = $metadata['kind'] ?? $metadata['target'] ?? 'reel';
        $extension = pathinfo($session->original_filename ?? '', PATHINFO_EXTENSION) ?: 'mp4';

        if ($kind === 'square') {
            $targetDir = $this->mediaStorageService->pathFor('square_videos', ['user_id' => $session->user_id]);
            $targetFilename = "square_video_{$session->id}.{$extension}";
        } else {
            // Par défaut, promotion comme vidéo de Reel
            $targetDir = $this->mediaStorageService->pathFor('reels_videos', ['user_id' => $session->user_id]);
            $targetFilename = "reel_video_{$session->id}.{$extension}";
        }

        $finalPath = "{$targetDir}/{$targetFilename}";

        // Déplacement du fichier temporaire vers l'emplacement définitif
        Storage::disk($session->disk)->makeDirectory($targetDir);
        Storage::disk($session->disk)->move($session->temp_path, $finalPath);

        $finalUrl = Storage::disk($session->disk)->url($finalPath);

        $session->status = 'completed';
        $session->completed_at = Carbon::now();
        $session->final_path = $finalPath;
        $session->final_url = $finalUrl;
        $session->mime_type = $detectedMime;
        $session->save();

        return [
            'id' => (string) $session->id,
            'path' => $finalPath,
            'url' => $finalUrl,
            'type' => str_starts_with($detectedMime, 'image/') ? 'image' : 'video',
            'mime_type' => $detectedMime,
            'file_size' => $actualSize,
            'disk' => $session->disk,
        ];
    }

    /**
     * Termine et annule une session de téléversement en supprimant le fichier temporaire.
     */
    public function terminateSession(TusUploadSession $session): bool
    {
        if (Storage::disk($session->disk)->exists($session->temp_path)) {
            Storage::disk($session->disk)->delete($session->temp_path);
        }

        $session->status = 'cancelled';

        return $session->save();
    }

    /**
     * Parse l'en-tête Tus `Upload-Metadata` (format: `key base64val, key2 base64val2`).
     *
     * @return array<string, string>
     */
    public function parseUploadMetadata(?string $header): array
    {
        if (empty($header)) {
            return [];
        }

        $parsed = [];
        $pairs = explode(',', $header);

        foreach ($pairs as $pair) {
            $pair = trim($pair);
            if (empty($pair)) {
                continue;
            }

            $parts = preg_split('/\s+/', $pair, 2);
            $key = trim($parts[0] ?? '');
            $val = trim($parts[1] ?? '');

            if (! empty($key)) {
                $decoded = base64_decode($val, true);
                $parsed[$key] = $decoded !== false ? $decoded : $val;
            }
        }

        return $parsed;
    }

    /**
     * Nettoie les sessions expirées et leurs fichiers temporaires résiduels.
     */
    public function cleanupExpiredSessions(): int
    {
        $expiredSessions = TusUploadSession::where('status', 'active')
            ->where('expires_at', '<=', Carbon::now())
            ->get();

        $count = 0;
        foreach ($expiredSessions as $session) {
            if (Storage::disk($session->disk)->exists($session->temp_path)) {
                Storage::disk($session->disk)->delete($session->temp_path);
            }
            $session->status = 'expired';
            $session->save();
            $count++;
        }

        return $count;
    }
}
