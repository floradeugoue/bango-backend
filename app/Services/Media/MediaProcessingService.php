<?php

namespace App\Services\Media;

use App\Enums\Media\MediaProcessingStatus;
use App\Jobs\Square\ProcessSquareVideoJob;
use App\Models\Square\PostMedia;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class MediaProcessingService
{
    /**
     * Relance le traitement asynchrone d'un PostMedia en échec.
     */
    public function retryPostMedia(PostMedia $postMedia, ?User $actor = null): bool
    {
        if ($actor && $postMedia->post && $postMedia->post->user_id !== $actor->id && ! $actor->isAdmin()) {
            throw new AccessDeniedHttpException("Vous n'êtes pas autorisé à relancer le traitement de ce média.");
        }

        if (! $postMedia->isFailed()) {
            throw new UnprocessableEntityHttpException("Seuls les médias en statut 'failed' peuvent être relancés (statut actuel: {$postMedia->status->value}).");
        }

        $postMedia->update([
            'status' => MediaProcessingStatus::Pending,
            'error_message' => null,
            'processing_started_at' => null,
        ]);

        ProcessSquareVideoJob::dispatch($postMedia);

        Log::info("MediaProcessingService: Relance manuelle du traitement de PostMedia #{$postMedia->id}.");

        return true;
    }

    /**
     * Détecte et nettoie les vidéos bloquées indéfiniment en statut 'processing' (Requirement 11).
     *
     * @return int Nombre de vidéos débloquées
     */
    public function cleanStuckProcessingVideos(int $timeoutMinutes = 15): int
    {
        $threshold = now()->subMinutes($timeoutMinutes);

        $stuckMedias = PostMedia::where('status', MediaProcessingStatus::Processing->value)
            ->where('processing_started_at', '<=', $threshold)
            ->get();

        $count = 0;
        foreach ($stuckMedias as $media) {
            $media->update([
                'status' => MediaProcessingStatus::Failed,
                'error_message' => "Timeout : le traitement a dépassé la durée maximale autorisée de {$timeoutMinutes} minutes.",
            ]);
            $count++;

            Log::warning("MediaProcessingService: PostMedia #{$media->id} bloqué depuis {$timeoutMinutes}m passé en 'failed'.");
        }

        return $count;
    }
}
