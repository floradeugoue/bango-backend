<?php

namespace App\Services\Square;

use App\Models\Reel\Sound;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoProcessingService
{
    protected ?bool $ffmpegAvailable = null;

    /**
     * Vérifie si FFmpeg est installé et accessible dans l'environnement d'exécution.
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
     * Pour les tests unitaires : permet de forcer la détection de FFmpeg.
     */
    public function setFfmpegAvailable(?bool $available): void
    {
        $this->ffmpegAvailable = $available;
    }

    /**
     * Traite un média vidéo Square avec ses métadonnées d'édition (découpage trim, couverture cover_ms, son).
     *
     * @param  array<string, mixed>  $editMetadata
     * @return array{
     *     media_url: string,
     *     poster_url: ?string,
     *     duration: ?int,
     *     edit_metadata: array<string, mixed>
     * }
     */
    public function processSquareVideo(
        string $mediaUrl,
        array $editMetadata,
        int|string $userId,
        ?int $originalDuration = null
    ): array {
        $normalizedMetadata = $this->normalizeEditMetadata($editMetadata);

        $finalMediaUrl = $mediaUrl;
        $posterUrl = null;
        $duration = $originalDuration;

        // 1. Découpage (Trim) & Calcul de la vraie durée
        if (isset($normalizedMetadata['trim'])) {
            $startMs = (int) $normalizedMetadata['trim']['start_ms'];
            $endMs = (int) $normalizedMetadata['trim']['end_ms'];

            if ($endMs > $startMs) {
                // Vraie durée en secondes
                $duration = (int) max(1, round(($endMs - $startMs) / 1000));

                $trimmedRelativePath = $this->trimVideo($mediaUrl, $startMs, $endMs, $userId);
                if ($trimmedRelativePath) {
                    $finalMediaUrl = Storage::disk('public')->url($trimmedRelativePath);
                }
            }
        }

        // 2. Image de couverture (Cover)
        if (isset($normalizedMetadata['cover_ms'])) {
            $coverMs = (int) $normalizedMetadata['cover_ms'];
            $coverRelativePath = $this->extractCoverFrame($mediaUrl, $coverMs, $userId);
            if ($coverRelativePath) {
                $posterUrl = Storage::disk('public')->url($coverRelativePath);
            }
        }

        // 3. Traitement et enrichissement du son
        if (isset($normalizedMetadata['sound']['id'])) {
            $soundId = (string) $normalizedMetadata['sound']['id'];
            $sound = Sound::find($soundId);

            if ($sound) {
                $normalizedMetadata['sound']['title'] = $sound->title;
                $normalizedMetadata['sound']['artist'] = $sound->artist;
                $normalizedMetadata['sound']['audio_url'] = $sound->audio_url
                    ? (str_starts_with($sound->audio_url, 'http') ? $sound->audio_url : Storage::disk('public')->url($sound->audio_url))
                    : null;
                $normalizedMetadata['sound']['audioUrl'] = $normalizedMetadata['sound']['audio_url'];
            }
        }

        return [
            'media_url' => $finalMediaUrl,
            'poster_url' => $posterUrl,
            'duration' => $duration,
            'edit_metadata' => $normalizedMetadata,
        ];
    }

    /**
     * Découpe la vidéo au stockage selon l'intervalle trim spécifié.
     */
    public function trimVideo(string $mediaUrl, int $startMs, int $endMs, int|string $userId): ?string
    {
        $inputPath = $this->resolveLocalDiskPath($mediaUrl);
        if (! $inputPath || ! file_exists($inputPath)) {
            return null;
        }

        $startSec = number_format($startMs / 1000, 3, '.', '');
        $durationSec = number_format(max(0.1, ($endMs - $startMs) / 1000), 3, '.', '');

        $ext = pathinfo($inputPath, PATHINFO_EXTENSION) ?: 'mp4';
        $uniqueId = (string) Str::uuid();
        $relativeOutputDir = "square/videos/{$userId}";
        $relativeOutputFile = "{$relativeOutputDir}/trimmed_{$uniqueId}.{$ext}";

        $disk = Storage::disk('public');
        $disk->makeDirectory($relativeOutputDir);
        $absoluteOutputDir = $disk->path($relativeOutputDir);
        if (! is_dir($absoluteOutputDir)) {
            @mkdir($absoluteOutputDir, 0755, true);
        }
        $absoluteOutputFile = $disk->path($relativeOutputFile);

        if ($this->isFfmpegAvailable()) {
            // Tentative 1 : découpage direct par flux (très rapide)
            $cmd = sprintf(
                'ffmpeg -y -ss %s -i %s -t %s -c copy %s',
                escapeshellarg($startSec),
                escapeshellarg($inputPath),
                escapeshellarg($durationSec),
                escapeshellarg($absoluteOutputFile)
            );

            try {
                $process = Process::run($cmd);
                if (! $process->successful() || ! file_exists($absoluteOutputFile) || filesize($absoluteOutputFile) === 0) {
                    // Tentative 2 : ré-encodage rapide si la copie de flux a échoué
                    $reencodeCmd = sprintf(
                        'ffmpeg -y -ss %s -i %s -t %s -c:v libx264 -preset ultrafast -c:a aac %s',
                        escapeshellarg($startSec),
                        escapeshellarg($inputPath),
                        escapeshellarg($durationSec),
                        escapeshellarg($absoluteOutputFile)
                    );
                    $processReencode = Process::run($reencodeCmd);
                    if (! $processReencode->successful() || ! file_exists($absoluteOutputFile) || filesize($absoluteOutputFile) === 0) {
                        Log::warning('VideoProcessingService: Échec du découpage vidéo via FFmpeg.', [
                            'error' => $processReencode->errorOutput(),
                        ]);

                        return null;
                    }
                }

                return $relativeOutputFile;
            } catch (\Throwable $e) {
                Log::warning('VideoProcessingService: Exception lors du découpage FFmpeg.', ['message' => $e->getMessage()]);

                return null;
            }
        }

        // Si FFmpeg n'est pas disponible en local, on consigne et on renvoie le chemin source
        return null;
    }

    /**
     * Extrait une image de couverture à l'instant cover_ms.
     */
    public function extractCoverFrame(string $mediaUrl, int $coverMs, int|string $userId): ?string
    {
        $uniqueId = (string) Str::uuid();
        $relativeOutputDir = "square/covers/{$userId}";
        $relativeOutputFile = "{$relativeOutputDir}/cover_{$uniqueId}.jpg";

        $disk = Storage::disk('public');
        $disk->makeDirectory($relativeOutputDir);
        $absoluteOutputDir = $disk->path($relativeOutputDir);
        if (! is_dir($absoluteOutputDir)) {
            @mkdir($absoluteOutputDir, 0755, true);
        }
        $absoluteOutputFile = $disk->path($relativeOutputFile);

        $inputPath = $this->resolveLocalDiskPath($mediaUrl);
        $coverSec = number_format(max(0, $coverMs / 1000), 3, '.', '');

        if ($this->isFfmpegAvailable() && $inputPath && file_exists($inputPath)) {
            $cmd = sprintf(
                'ffmpeg -y -ss %s -i %s -vframes 1 -q:v 2 %s',
                escapeshellarg($coverSec),
                escapeshellarg($inputPath),
                escapeshellarg($absoluteOutputFile)
            );

            try {
                $process = Process::run($cmd);
                if ($process->successful() && file_exists($absoluteOutputFile) && filesize($absoluteOutputFile) > 0) {
                    return $relativeOutputFile;
                }
            } catch (\Throwable $e) {
                Log::warning('VideoProcessingService: Exception lors de l\'extraction de couverture FFmpeg.', [
                    'message' => $e->getMessage(),
                ]);
            }
        }

        // Fallback sans FFmpeg ou si extraction échoue : génération d'un poster JPEG valide
        $this->generateFallbackPoster($absoluteOutputFile);

        return file_exists($absoluteOutputFile) ? $relativeOutputFile : null;
    }

    /**
     * Génère un poster JPEG élégant de secours pour l'affichage de la vignette dans le fil.
     */
    protected function generateFallbackPoster(string $targetPath): void
    {
        if (function_exists('imagecreatetruecolor')) {
            $img = @imagecreatetruecolor(1280, 720);
            if ($img) {
                // Fond sombre moderne (#1e2029)
                $bgColor = imagecolorallocate($img, 30, 32, 41);
                imagefilledrectangle($img, 0, 0, 1280, 720, $bgColor);

                // Accentuation visuelle subtile
                $accentColor = imagecolorallocate($img, 52, 60, 76);
                imagefilledrectangle($img, 100, 100, 1180, 620, $accentColor);

                @imagejpeg($img, $targetPath, 85);
                @imagedestroy($img);

                return;
            }
        }

        // En-tête binaire JPEG minimal 1x1 pixel valide
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
    protected function resolveLocalDiskPath(string $urlOrPath): ?string
    {
        $cleanPath = $urlOrPath;

        // Supprime le préfixe HTTP / Domaine éventuel
        $cleanPath = preg_replace('#^https?://[^/]+/#', '', $cleanPath);

        // Supprime les préfixes storage/ ou /storage/
        $cleanPath = preg_replace('#^/?storage/#', '', $cleanPath);
        $cleanPath = ltrim($cleanPath, '/');

        $disk = Storage::disk('public');
        if ($disk->exists($cleanPath)) {
            return $disk->path($cleanPath);
        }

        // Essai sur le chemin direct
        if (file_exists($urlOrPath)) {
            return $urlOrPath;
        }

        return null;
    }

    /**
     * Normalise les données d'édition pour supporter à la fois snake_case et camelCase.
     *
     * @param  array<string, mixed>  $editMetadata
     * @return array<string, mixed>
     */
    public function normalizeEditMetadata(array $editMetadata): array
    {
        $normalized = [];

        // Trim
        $trim = $editMetadata['trim'] ?? null;
        if (is_array($trim)) {
            $startMs = $trim['start_ms'] ?? $trim['startMs'] ?? 0;
            $endMs = $trim['end_ms'] ?? $trim['endMs'] ?? null;

            if ($endMs !== null) {
                $normalized['trim'] = [
                    'start_ms' => (int) $startMs,
                    'startMs' => (int) $startMs,
                    'end_ms' => (int) $endMs,
                    'endMs' => (int) $endMs,
                ];
            }
        }

        // Cover
        $coverMs = $editMetadata['cover_ms'] ?? $editMetadata['coverMs'] ?? null;
        if ($coverMs !== null) {
            $normalized['cover_ms'] = (int) $coverMs;
            $normalized['coverMs'] = (int) $coverMs;
        }

        // Sound
        $sound = $editMetadata['sound'] ?? null;
        if (is_array($sound)) {
            $soundId = $sound['id'] ?? $sound['sound_id'] ?? $sound['soundId'] ?? null;
            $startMs = $sound['start_ms'] ?? $sound['startMs'] ?? 0;

            if ($soundId) {
                $normalized['sound'] = [
                    'id' => (string) $soundId,
                    'sound_id' => (string) $soundId,
                    'soundId' => (string) $soundId,
                    'start_ms' => (int) $startMs,
                    'startMs' => (int) $startMs,
                ];
            }
        }

        // Conserver les autres clés additionnelles (filtres, texte, etc.)
        foreach ($editMetadata as $k => $v) {
            if (! in_array($k, ['trim', 'cover_ms', 'coverMs', 'sound'], true)) {
                $normalized[$k] = $v;
            }
        }

        return $normalized;
    }
}
