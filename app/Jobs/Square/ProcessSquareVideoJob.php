<?php

namespace App\Jobs\Square;

use App\Models\Square\PostMedia;
use App\Services\Media\VideoTranscodingService;
use App\Services\Square\VideoProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessSquareVideoJob implements ShouldQueue
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

    /**
     * Durée maximale d'exécution du Job (5 minutes).
     */
    public int $timeout = 300;

    /**
     * Fichiers temporaires à nettoyer à la fin du traitement.
     *
     * @var array<string>
     */
    protected array $tempFilesToClean = [];

    public function __construct(public PostMedia $postMedia)
    {
        $queueName = config('media.ffmpeg_queue', 'ffmpeg');
        if (! empty($queueName)) {
            $this->onQueue($queueName);
        }
    }

    /**
     * Exécute le traitement vidéo asynchrone FFmpeg du PostMedia.
     */
    public function handle(
        VideoProcessingService $processingService,
        VideoTranscodingService $transcodingService
    ): void {
        // 1. Rechargement du modèle frais
        $this->postMedia->refresh();

        // Idempotence : si le média est déjà complété ou n'existe plus, on s'arrête
        if ($this->postMedia->isCompleted()) {
            return;
        }

        // 2. Prévention des traitements simultanés (Requirement 8)
        $lockKey = "ffmpeg_video_lock:post_media:{$this->postMedia->id}";
        $lock = Cache::lock($lockKey, 360);

        if (! $lock->get()) {
            Log::info("ProcessSquareVideoJob: traitement déjà en cours pour PostMedia #{$this->postMedia->id}. Job ignoré.");

            return;
        }

        // 3. Limitation de concurrence pour protéger les ressources CPU/RAM du VPS (Requirement 9)
        $useRedisLimiter = config('queue.default') === 'redis' || config('cache.default') === 'redis';
        $maxConcurrency = (int) config('media.ffmpeg_max_concurrency', 2);

        if ($useRedisLimiter) {
            try {
                Redis::funnel('ffmpeg_transcoding_limiter')
                    ->limit($maxConcurrency)
                    ->block(0)
                    ->then(
                        function () use ($processingService, $transcodingService, $lock) {
                            $this->process($processingService, $transcodingService, $lock);
                        },
                        function () use ($lock) {
                            $lock->release();
                            // Si la limite de concurrence FFmpeg est atteinte, relâcher le job avec 5s de délai
                            Log::info("ProcessSquareVideoJob: limite de concurrence FFmpeg atteinte ({$maxConcurrency} actifs). Re-queue avec délai.");
                            $this->release(5);
                        }
                    );

                return;
            } catch (Throwable $e) {
                // Si l'appel Redis échoue (ex: Redis non démarré en local), continuer en exécution directe avec le verrou
                Log::warning("ProcessSquareVideoJob: Redis funnel indisponible, exécution directe avec verrou local : {$e->getMessage()}");
            }
        }

        $this->process($processingService, $transcodingService, $lock);
    }

    /**
     * Déroulement réel du traitement vidéo FFmpeg.
     */
    protected function process(
        VideoProcessingService $processingService,
        VideoTranscodingService $transcodingService,
        $lock
    ): void {
        try {
            // État PROCESSING (Requirement 6)
            $this->postMedia->markAsProcessing();

            $post = $this->postMedia->post;
            $userId = $post?->user_id ?? 1;
            $disk = 'public';

            $currentMediaUrl = $this->postMedia->media_url;
            $currentPosterUrl = $this->postMedia->poster_url;
            $currentDuration = $this->postMedia->duration;
            $editMetadata = $this->postMedia->edit_metadata ?? [];

            // 1. Découpage Trim & Extraction Cover Frame (si edit_metadata présent)
            if (! empty($editMetadata) && (isset($editMetadata['trim']) || isset($editMetadata['cover_ms']))) {
                $processed = $processingService->processSquareVideo(
                    $currentMediaUrl,
                    $editMetadata,
                    $userId,
                    $currentDuration
                );

                if (! empty($processed['media_url']) && $processed['media_url'] !== $currentMediaUrl) {
                    $oldMediaUrl = $currentMediaUrl;
                    $currentMediaUrl = $processed['media_url'];
                    $editMetadata = $processed['edit_metadata'] ?? $editMetadata;

                    // Si un fichier découpé temporaire a été créé, on le traquera pour nettoyage ultérieur si besoin
                    $trimmedPath = $this->extractStoragePath($currentMediaUrl);
                    if ($trimmedPath && Storage::disk($disk)->exists($trimmedPath)) {
                        $this->tempFilesToClean[] = Storage::disk($disk)->path($trimmedPath);
                    }
                }

                if (! empty($processed['poster_url'])) {
                    $currentPosterUrl = $processed['poster_url'];
                }

                if (! empty($processed['duration'])) {
                    $currentDuration = $processed['duration'];
                }
            }

            // 2. Transcodage multi-résolution (480p, 720p, 1080p) et vignette poster (Requirement 3 & 12)
            $transcoded = $transcodingService->transcode(
                $currentMediaUrl,
                'square/renditions',
                $userId,
                $disk
            );

            $finalPlaybackUrl = $transcoded['default_url'] ?? $currentMediaUrl;
            $finalPosterUrl = $currentPosterUrl ?? $transcoded['poster_url'];
            $renditions = $transcoded['renditions'] ?? [];

            // 3. Passage à l'état COMPLETED (Requirement 6)
            $this->postMedia->markAsCompleted(
                mediaUrl: $finalPlaybackUrl,
                posterUrl: $finalPosterUrl,
                renditions: $renditions,
                duration: $currentDuration
            );

            Log::info("ProcessSquareVideoJob: PostMedia #{$this->postMedia->id} transcodé avec succès.");
        } catch (Throwable $e) {
            Log::error("ProcessSquareVideoJob: Échec du traitement pour PostMedia #{$this->postMedia->id}: {$e->getMessage()}", [
                'trace' => $e->getTraceAsString(),
            ]);

            // Si c'est la dernière tentative, marquer définitivement comme FAILED
            if ($this->attempts() >= $this->tries) {
                $this->postMedia->markAsFailed("Échec FFmpeg après {$this->tries} tentatives: {$e->getMessage()}");
            }

            throw $e;
        } finally {
            // Nettoyage des fichiers temporaires (Requirement 10)
            $this->cleanupTempFiles();

            // Libération du verrou atomique (Requirement 8)
            try {
                $lock->release();
            } catch (Throwable) {
                // Ignore lock release failures
            }
        }
    }

    /**
     * En cas d'échec définitif du Job (ex: timeout ou max tries atteints).
     * Garantit que la vidéo ne reste JAMAIS bloquée indéfiniment en 'processing' (Requirement 11).
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $this->postMedia->refresh();
            $message = $exception ? $exception->getMessage() : 'Le traitement vidéo a échoué.';
            $this->postMedia->markAsFailed($message);
            Log::warning("ProcessSquareVideoJob: PostMedia #{$this->postMedia->id} marqué comme FAILED suite à l'échec définitif du job: {$message}");
        } catch (Throwable $e) {
            Log::error("ProcessSquareVideoJob: Erreur lors de la mise à jour FAILED: {$e->getMessage()}");
        } finally {
            $this->cleanupTempFiles();
        }
    }

    /**
     * Ajoute un fichier temporaire à supprimer après traitement.
     */
    public function addTempFileToClean(string $filePath): void
    {
        $this->tempFilesToClean[] = $filePath;
    }

    /**
     * Supprime les fichiers temporaires créés lors du traitement.
     */
    public function cleanupTempFiles(): void
    {
        foreach ($this->tempFilesToClean as $filePath) {
            try {
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            } catch (Throwable) {
                // Ignore unlink errors
            }
        }
        $this->tempFilesToClean = [];
    }

    /**
     * Extrait le chemin relatif dans storage à partir d'une URL.
     */
    protected function extractStoragePath(string $url): ?string
    {
        $parsed = parse_url($url, PHP_URL_PATH);
        if (! $parsed) {
            return null;
        }

        if (str_starts_with($parsed, '/storage/')) {
            return substr($parsed, strlen('/storage/'));
        }

        return ltrim($parsed, '/');
    }
}
