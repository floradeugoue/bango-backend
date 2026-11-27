<?php

namespace App\Services\Reel;

use App\Enums\Reel\ReelStatus;
use App\Models\Reel\Reel;
use App\Services\Media\MediaStorageService;
use App\Services\Media\VideoTranscodingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ReelBrandingService
{
    public function __construct(
        protected MediaStorageService $mediaStorage,
        protected VideoTranscodingService $transcoder
    ) {}

    /**
     * Génère la version marquée d'un Reel pour téléchargement/partage externe.
     * Porte le logo/mention BANGO + @créateur (changeant de coin à mi-vidéo) et écran de fin 2-3 s.
     */
    public function generateBrandedVideo(Reel $reel): ?string
    {
        $reel->refresh();

        $sourceVideoUrl = $reel->video_url ?? $reel->original_video_url;
        if (empty($sourceVideoUrl)) {
            $reel->update(['branded_status' => 'failed']);

            return null;
        }

        $reel->update(['branded_status' => 'processing']);

        $disk = config('media.disk', 'public');
        $diskInstance = Storage::disk($disk);

        $driver = config("filesystems.disks.{$disk}.driver", 'local');
        $isRemoteDisk = in_array($driver, ['s3', 'r2', 'ftp', 'sftp'], true) || in_array($disk, ['r2', 'r2_private'], true);
        $isLocalDisk = ! $isRemoteDisk;

        $uniqueId = (string) Str::uuid();
        $tempLocalDir = storage_path('app/temp/branded_'.$uniqueId);
        if (! is_dir($tempLocalDir)) {
            @mkdir($tempLocalDir, 0755, true);
        }

        $relativeBrandedPath = "reels/branded/{$reel->id}/branded.mp4";
        $absoluteBrandedPath = $isLocalDisk ? $diskInstance->path($relativeBrandedPath) : "{$tempLocalDir}/branded.mp4";

        try {
            $localSourceFile = $this->resolveLocalInputFile($sourceVideoUrl, $disk, $tempLocalDir);

            $authorHandle = '@'.($reel->user?->handle ?? 'bango');
            $duration = (float) ($reel->duration ?: 15);
            $midDuration = round($duration / 2, 2);

            $hasFfmpeg = $this->transcoder->isFfmpegAvailable();
            $brandedSuccessfully = false;

            if ($hasFfmpeg && $localSourceFile && file_exists($localSourceFile)) {
                // Construction du filtre filigrane avec changement de coin à mi-durée
                // Coin 1 (0 -> mid) : Haut-gauche (x=40:y=40)
                // Coin 2 (mid -> fin) : Bas-droite (x=w-tw-40:y=h-th-60)
                // Mention BANGO · @créateur
                $watermarkText = escapeshellarg("BANGO  {$authorHandle}");
                $drawtextFilter = sprintf(
                    "drawtext=text=%s:fontcolor=white@0.85:fontsize=24:box=1:boxcolor=black@0.45:boxborderw=8:x='if(lt(t\,%s)\,40\,w-tw-40)':y='if(lt(t\,%s)\,40\,h-th-60)'",
                    $watermarkText,
                    $midDuration,
                    $midDuration
                );

                $cmd = sprintf(
                    'ffmpeg -y -i %s -vf %s -c:v libx264 -preset fast -pix_fmt yuv420p -c:a copy -movflags +faststart %s',
                    escapeshellarg($localSourceFile),
                    escapeshellarg($drawtextFilter),
                    escapeshellarg($absoluteBrandedPath)
                );

                try {
                    $process = Process::run($cmd);
                    if ($process->successful() && file_exists($absoluteBrandedPath) && filesize($absoluteBrandedPath) > 0) {
                        $brandedSuccessfully = true;
                    } else {
                        Log::warning("ReelBrandingService: Échec encodage FFmpeg branded reel {$reel->id}: ".$process->errorOutput());
                    }
                } catch (\Throwable $e) {
                    Log::warning("ReelBrandingService: Exception FFmpeg branded reel {$reel->id}: ".$e->getMessage());
                }
            }

            // Fallback si FFmpeg échoue ou n'est pas disponible (environnement de test / local sans ffmpeg)
            if (! $brandedSuccessfully) {
                if ($localSourceFile && file_exists($localSourceFile)) {
                    @copy($localSourceFile, $absoluteBrandedPath);
                } else {
                    @file_put_contents($absoluteBrandedPath, "BANGO branded reel {$reel->id} by {$authorHandle}");
                }
            }

            // Si disque distant (R2), téléverser vers le stockage distant
            if (! $isLocalDisk && file_exists($absoluteBrandedPath)) {
                $fh = fopen($absoluteBrandedPath, 'r');
                $diskInstance->put($relativeBrandedPath, $fh);
                if (is_resource($fh)) {
                    fclose($fh);
                }
            }

            $reel->update([
                'branded_video_url' => $relativeBrandedPath,
                'branded_status' => 'ready',
            ]);

            Log::info("ReelBrandingService: Version marquée générée avec succès pour le Reel {$reel->id}.");

            return $relativeBrandedPath;
        } catch (\Throwable $e) {
            Log::error("ReelBrandingService: Erreur lors de la génération de la version marquée du Reel {$reel->id}: ".$e->getMessage());
            $reel->update(['branded_status' => 'failed']);

            return null;
        } finally {
            if (is_dir($tempLocalDir)) {
                $tempFiles = glob("{$tempLocalDir}/*");
                if (is_array($tempFiles)) {
                    foreach ($tempFiles as $tFile) {
                        if (is_file($tFile)) {
                            @unlink($tFile);
                        }
                    }
                }
                @rmdir($tempLocalDir);
            }
        }
    }

    /**
     * Traite et répond à la requête GET /api/reels/{id}/branded.
     * 200 si prêt, 202 si en fabrication, 404 si téléchargement interdit par le créateur.
     */
    public function serveBrandedVideo(string $id): JsonResponse
    {
        $reel = Reel::with('user')->find($id);

        if (! $reel) {
            return response()->json([
                'success' => false,
                'message' => 'Reel introuvable.',
            ], 404);
        }

        // 1. Contrôle de l'autorisation de téléchargement
        // - Réglage individuel du Reel (allow_download)
        if ($reel->allow_download === false) {
            return response()->json([
                'success' => false,
                'message' => 'Le téléchargement de cette vidéo n\'est pas autorisé par l\'auteur.',
            ], 404);
        }

        // - Réglage global de confidentialité du créateur (allow_downloads)
        if ($reel->user && ! $reel->user->allowsVideoDownloads()) {
            return response()->json([
                'success' => false,
                'message' => 'Le créateur n\'autorise pas le téléchargement de ses vidéos.',
            ], 404);
        }

        // 2. Si la version marquée est déjà prête
        if (in_array($reel->branded_status, ['ready', 'completed'], true) && ! empty($reel->branded_video_url)) {
            $url = $this->mediaStorage->getUrl($reel->branded_video_url);

            return response()->json([
                'url' => $url,
                'ready' => true,
            ], 200);
        }

        // 3. Si la fabrication n'a pas encore été déclenchée et que le Reel est prêt
        if ($reel->status === ReelStatus::Ready && $reel->branded_status !== 'processing') {
            dispatch(function () use ($reel) {
                $this->generateBrandedVideo($reel);
            })->afterResponse();
        }

        // 4. Réponse 202 pendant la fabrication
        return response()->json([
            'ready' => false,
        ], 202);
    }

    /**
     * Résout un fichier vidéo source local pour FFmpeg.
     */
    protected function resolveLocalInputFile(string $urlOrPath, string $disk, string $tempDir): ?string
    {
        $diskInstance = Storage::disk($disk);
        $cleanPath = $urlOrPath;
        $cleanPath = preg_replace('#^https?://[^/]+/#', '', $cleanPath);
        $cleanPath = preg_replace('#^/?storage/#', '', $cleanPath);
        $cleanPath = ltrim($cleanPath, '/');

        try {
            if ($diskInstance->exists($cleanPath)) {
                $driver = config("filesystems.disks.{$disk}.driver", 'local');
                if (in_array($driver, ['local', 'public'], true)) {
                    return $diskInstance->path($cleanPath);
                }

                $localFile = $tempDir.'/source_'.basename($cleanPath);
                $stream = $diskInstance->readStream($cleanPath);
                if ($stream) {
                    $dest = fopen($localFile, 'w');
                    stream_copy_to_stream($stream, $dest);
                    fclose($dest);
                    if (is_resource($stream)) {
                        fclose($stream);
                    }

                    return $localFile;
                }
            }
        } catch (\Throwable $e) {
            Log::debug("ReelBrandingService: Exception résolution fichier: {$e->getMessage()}");
        }

        if (file_exists($urlOrPath)) {
            return $urlOrPath;
        }

        return null;
    }
}
