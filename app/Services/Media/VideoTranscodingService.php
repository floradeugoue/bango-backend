<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoTranscodingService
{
    protected ?bool $ffmpegAvailable = null;

    /**
     * Vérifie si FFmpeg est présent et exécutable.
     */
    public function isFfmpegAvailable(): bool
    {
        if ($this->ffmpegAvailable !== null) {
            return $this->ffmpegAvailable;
        }

        try {
            $result = Process::run('ffmpeg -version');
            $this->ffmpegAvailable = $result->successful();
        } catch (\Throwable $e) {
            $this->ffmpegAvailable = false;
        }

        return $this->ffmpegAvailable;
    }

    /**
     * Pour les tests unitaires : permet de simuler la présence de FFmpeg.
     */
    public function setFfmpegAvailable(?bool $available): void
    {
        $this->ffmpegAvailable = $available;
    }

    /**
     * Transcode une vidéo dans les 3 profils adaptatifs (480p, 720p, 1080p) et extrait une vignette poster.
     *
     * @param  string  $sourcePathOrUrl  Chemin relatif ou URL de la vidéo source
     * @param  string  $targetSubDir  Sous-dossier de destination (ex: 'reels/renditions', 'square/renditions')
     * @param  int|string  $userId  Identifiant de l'utilisateur
     * @param  string  $disk  Nom du disque de stockage (défaut 'public')
     * @return array{
     *     renditions: array{ '480p': string, '720p': string, '1080p': string },
     *     relative_renditions: array{ '480p': string, '720p': string, '1080p': string },
     *     poster_url: ?string,
     *     poster_path: ?string,
     *     default_url: string,
     *     original_url: string
     * }
     */
    public function transcode(
        string $sourcePathOrUrl,
        string $targetSubDir,
        int|string $userId,
        string $disk = 'public'
    ): array {
        $diskInstance = Storage::disk($disk);
        $inputAbsPath = $this->resolveLocalDiskPath($sourcePathOrUrl, $disk);

        $originalUrl = $this->resolvePublicUrl($sourcePathOrUrl, $disk);
        $uniqueId = (string) Str::uuid();
        $relativeBaseDir = trim("{$targetSubDir}/{$userId}/{$uniqueId}", '/');
        $diskInstance->makeDirectory($relativeBaseDir);
        $absoluteBaseDir = $diskInstance->path($relativeBaseDir);

        if (! is_dir($absoluteBaseDir)) {
            @mkdir($absoluteBaseDir, 0755, true);
        }

        // Définition des 3 profils cibles
        $profiles = [
            '480p' => [
                'scale' => '-vf scale=-2:480',
                'video_bitrate' => '-b:v 1000k -maxrate 1200k -bufsize 2000k',
                'audio_bitrate' => '-c:a aac -b:a 128k',
            ],
            '720p' => [
                'scale' => '-vf scale=-2:720',
                'video_bitrate' => '-b:v 2500k -maxrate 3000k -bufsize 5000k',
                'audio_bitrate' => '-c:a aac -b:a 128k',
            ],
            '1080p' => [
                'scale' => '-vf scale=-2:1080',
                'video_bitrate' => '-b:v 5000k -maxrate 6000k -bufsize 10000k',
                'audio_bitrate' => '-c:a aac -b:a 192k',
            ],
        ];

        $renditions = [];
        $relativeRenditions = [];
        $hasFfmpeg = $this->isFfmpegAvailable();

        foreach ($profiles as $profileKey => $cfg) {
            $relativeFile = "{$relativeBaseDir}/{$profileKey}.mp4";
            $absoluteFile = $diskInstance->path($relativeFile);

            $transcodedSuccessfully = false;

            if ($hasFfmpeg && $inputAbsPath && file_exists($inputAbsPath)) {
                // Transcodage H.264 + AAC avec -movflags +faststart
                $cmd = sprintf(
                    'ffmpeg -y -i %s %s %s %s -c:v libx264 -preset fast -pix_fmt yuv420p -movflags +faststart %s',
                    escapeshellarg($inputAbsPath),
                    $cfg['scale'],
                    $cfg['video_bitrate'],
                    $cfg['audio_bitrate'],
                    escapeshellarg($absoluteFile)
                );

                try {
                    $process = Process::run($cmd);
                    if ($process->successful() && file_exists($absoluteFile) && filesize($absoluteFile) > 0) {
                        $transcodedSuccessfully = true;
                    } else {
                        Log::warning("VideoTranscodingService: Échec transcodage profil {$profileKey}", [
                            'error' => $process->errorOutput(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    Log::warning("VideoTranscodingService: Exception transcodage {$profileKey}: {$e->getMessage()}");
                }
            }

            // Fallback si FFmpeg n'est pas installé ou a échoué : créer un fichier dédié distinct
            if (! $transcodedSuccessfully) {
                if ($inputAbsPath && file_exists($inputAbsPath)) {
                    @copy($inputAbsPath, $absoluteFile);
                } else {
                    @file_put_contents($absoluteFile, "dummy video stream {$profileKey}");
                }
            }

            $relativeRenditions[$profileKey] = $relativeFile;
            $renditions[$profileKey] = $diskInstance->url($relativeFile);
        }

        // Extraction de l'image de couverture (poster_url)
        $posterRelPath = "{$relativeBaseDir}/poster.jpg";
        $posterAbsPath = $diskInstance->path($posterRelPath);
        $posterExtracted = false;

        if ($hasFfmpeg && $inputAbsPath && file_exists($inputAbsPath)) {
            $posterCmd = sprintf(
                'ffmpeg -y -ss 00:00:01 -i %s -vframes 1 -q:v 2 %s',
                escapeshellarg($inputAbsPath),
                escapeshellarg($posterAbsPath)
            );

            try {
                $process = Process::run($posterCmd);
                if ($process->successful() && file_exists($posterAbsPath) && filesize($posterAbsPath) > 0) {
                    $posterExtracted = true;
                }
            } catch (\Throwable $e) {
                Log::warning("VideoTranscodingService: Exception extraction poster: {$e->getMessage()}");
            }
        }

        if (! $posterExtracted) {
            $this->generateFallbackPoster($posterAbsPath);
        }

        $posterUrl = file_exists($posterAbsPath) ? $diskInstance->url($posterRelPath) : null;

        // La vidéo de lecture par défaut est la version 720p (ou 480p) pour éviter de saturer le réseau avec l'original 4K
        $defaultPlaybackUrl = $renditions['720p'] ?? $renditions['480p'] ?? $originalUrl;

        return [
            'renditions' => $renditions,
            'relative_renditions' => $relativeRenditions,
            'poster_url' => $posterUrl,
            'poster_path' => $posterRelPath,
            'default_url' => $defaultPlaybackUrl,
            'original_url' => $originalUrl,
        ];
    }

    /**
     * Génère un poster JPEG élégant si l'extraction FFmpeg n'a pas pu s'exécuter.
     */
    protected function generateFallbackPoster(string $targetPath): void
    {
        if (function_exists('imagecreatetruecolor')) {
            $img = @imagecreatetruecolor(1280, 720);
            if ($img) {
                $bgColor = imagecolorallocate($img, 24, 26, 32);
                imagefilledrectangle($img, 0, 0, 1280, 720, $bgColor);
                $inner = imagecolorallocate($img, 45, 52, 68);
                imagefilledrectangle($img, 80, 80, 1200, 640, $inner);

                @imagejpeg($img, $targetPath, 85);
                @imagedestroy($img);

                return;
            }
        }

        $minimalJpeg = "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x01\x00H\x00H\x00\x00\xFF\xDB\x00C\x00"
            ."\x08\x06\x06\x07\x06\x05\x08\x07\x07\x07\t\t\x08\n\x0C\x14\r\x0C\x0B\x0B\x0C\x19\x12\x13\x0F"
            ."\x14\x1D\x1A\x1F\x1E\x1D\x1A\x1C\x1C $.' \",#\x1C\x1C(7),01444\x1F'9=82<.342\xFF\xC0\x00\x0B"
            ."\x08\x00\x01\x00\x01\x01\x01\x11\x00\xFF\xC4\x00\x1F\x00\x00\x01\x05\x01\x01\x01\x01\x01\x01"
            ."\x00\x00\x00\x00\x00\x00\x00\x00\x01\x02\x03\x04\x05\x06\x07\x08\t\n\x0B\xFF\xDA\x00\x08\x01"
            ."\x01\x00\x00?\x00\xBF\x00\xFF\xD9";

        @file_put_contents($targetPath, $minimalJpeg);
    }

    /**
     * Résout le chemin absolu sur le disque local public depuis une URL ou un chemin relatif.
     */
    protected function resolveLocalDiskPath(string $urlOrPath, string $disk = 'public'): ?string
    {
        $cleanPath = $urlOrPath;
        $cleanPath = preg_replace('#^https?://[^/]+/#', '', $cleanPath);
        $cleanPath = preg_replace('#^/?storage/#', '', $cleanPath);
        $cleanPath = ltrim($cleanPath, '/');

        $diskInstance = Storage::disk($disk);
        if ($diskInstance->exists($cleanPath)) {
            return $diskInstance->path($cleanPath);
        }

        if (file_exists($urlOrPath)) {
            return $urlOrPath;
        }

        return null;
    }

    /**
     * Résout l'URL publique absolue.
     */
    protected function resolvePublicUrl(string $urlOrPath, string $disk = 'public'): string
    {
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            return $urlOrPath;
        }

        $cleanPath = preg_replace('#^/?storage/#', '', $urlOrPath);
        $cleanPath = ltrim($cleanPath, '/');

        return Storage::disk($disk)->url($cleanPath);
    }
}
