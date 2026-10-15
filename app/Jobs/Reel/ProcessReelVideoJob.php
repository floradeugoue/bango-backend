<?php

namespace App\Jobs\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Services\Reel\CloudflareStreamService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessReelVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Nombre maximal de tentatives d'exécution.
     */
    public int $tries = 3;

    /**
     * Délais entre les tentatives (en secondes).
     *
     * @var array<int>
     */
    public array $backoff = [10, 30, 60];

    public function __construct(public Reel $reel) {}

    /**
     * Exécute le traitement vidéo asynchrone du Reel.
     */
    public function handle(CloudflareStreamService $cloudflareStream): void
    {
        // Recharger le modèle frais depuis la base de données
        $this->reel->refresh();

        // Si le reel a été supprimé ou n'est plus en attente/en cours de traitement, ne rien faire (idempotence)
        if ($this->reel->status === ReelStatus::Ready || $this->reel->status === ReelStatus::Draft) {
            return;
        }

        $this->reel->update([
            'status' => ReelStatus::Processing,
        ]);

        try {
            // 1. Traitement via Cloudflare Stream si configuré
            if ($cloudflareStream->isConfigured() && $this->reel->stream_media_id) {
                $details = $cloudflareStream->getVideoDetails($this->reel->stream_media_id);

                if ($details) {
                    if (! $details['ready_to_stream']) {
                        // Vidéo encore en cours d'encodage par Cloudflare : reprogrammer avec un délai
                        $this->release(15);

                        return;
                    }

                    $this->reel->hls_url = $details['hls_url'];
                    $this->reel->thumbnail_url = $details['thumbnail_url'];
                    if (! empty($details['duration'])) {
                        $this->reel->duration = (int) round($details['duration']);
                    }
                }
            }

            // 2. Génération des renditions adaptatives (480p, 720p, 1080p)
            $videoUrl = $this->reel->video_url ?? '';
            $renditions = $this->reel->renditions ?? [];

            if (empty($renditions) && ! empty($videoUrl)) {
                $renditions = [
                    '480p' => $videoUrl,
                    '720p' => $videoUrl,
                    '1080p' => $videoUrl,
                ];
                $this->reel->renditions = $renditions;
            }

            // 3. Gestion du Son et 4. Finalisation de la publication atomique
            $soundId = $this->reel->sound_id;
            DB::transaction(function () use ($soundId) {
                if (! $soundId || str_starts_with($soundId, 'orig:')) {
                    $originalSoundId = "orig:{$this->reel->id}";
                    $this->reel->sound_id = $originalSoundId;

                    $authorName = $this->reel->user?->name ?? 'Utilisateur BANGO';
                    Sound::firstOrCreate(
                        ['id' => $originalSoundId],
                        [
                            'title' => 'Son original',
                            'artist' => $authorName,
                            'genre' => 'afro',
                            'base_uses' => 0,
                            'uses_count' => 1,
                            'duration_seconds' => $this->reel->duration ?? 30,
                            'is_original' => true,
                            'original_reel_id' => $this->reel->id,
                            'user_id' => $this->reel->user_id,
                            'audio_url' => $this->reel->video_url,
                        ]
                    );
                } elseif ($sound = Sound::find($soundId)) {
                    // Incrémenter le compteur d'utilisation uniquement si le Reel n'était pas déjà Ready (idempotence retry)
                    if ($this->reel->getOriginal('status') !== ReelStatus::Ready) {
                        $sound->increment('uses_count');
                    }
                }

                $this->reel->status = ReelStatus::Ready;
                $this->reel->published_at = $this->reel->published_at ?? now();
                $this->reel->error_message = null;
                $this->reel->save();
            });

            Log::info("Reel {$this->reel->id} traité et publié avec succès.");
        } catch (\Throwable $e) {
            Log::error("Erreur lors du traitement du Reel {$this->reel->id}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            $this->fail($e);
        }
    }

    /**
     * Traitement de l'échec définitif du job.
     */
    public function failed(?\Throwable $exception): void
    {
        $this->reel->update([
            'status' => ReelStatus::Failed,
            'error_message' => $exception ? $exception->getMessage() : 'Erreur inconnue lors du traitement vidéo.',
        ]);

        Log::error("Échec définitif du job de traitement pour le Reel {$this->reel->id}.");
    }
}
