<?php

namespace App\Services\Media;

use App\Enums\Square\PostMediaKind;
use App\Models\Reel\Reel;
use App\Models\Reel\Sound;
use App\Models\Square\PostMedia;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaStorageService
{
    /**
     * Types MIME images autorisés.
     *
     * @var array<string>
     */
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /**
     * Types MIME vidéos autorisés (pour les clips et reels).
     *
     * @var array<string>
     */
    public const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
        'video/quicktime',
        'video/webm',
        'video/3gpp',
        'video/x-m4v',
    ];

    /**
     * Types MIME audio autorisés (pour les sons du catalogue et originaux).
     *
     * @var array<string>
     */
    public const ALLOWED_AUDIO_MIMES = [
        'audio/mpeg',
        'audio/mp3',
        'audio/wav',
        'audio/x-wav',
        'audio/aac',
        'audio/ogg',
        'audio/x-m4a',
        'audio/mp4',
    ];

    /**
     * Obtenir le nom du disque de stockage configuré.
     */
    public function getDisk(): string
    {
        return config('media.disk', 'public');
    }

    /**
     * Résout un chemin centralisé à partir de la configuration media.paths.
     *
     * @param  array<string, mixed>  $params
     */
    public function pathFor(string $type, array $params = []): string
    {
        $template = config("media.paths.{$type}");

        if (! $template) {
            // Si le type est déjà un chemin direct ou non défini
            $template = match ($type) {
                'reels_videos' => 'reels/videos/{user_id}',
                'reels_covers' => 'reels/covers/{user_id}',
                'reels_thumbnails' => 'reels/thumbnails/{user_id}',
                'reels_drafts' => 'reels/drafts/{user_id}',
                'reels_temp' => 'reels/temp',
                'sounds_catalogue' => 'sounds/catalogue',
                'sounds_originals' => 'sounds/originals/{user_id}',
                'sounds_temp' => 'sounds/temp',
                'square_images' => 'square/images/{user_id}',
                'square_videos' => 'square/videos/{user_id}',
                'square_temp' => 'square/temp',
                'stories_images' => 'stories/images/{user_id}',
                'stories_videos' => 'stories/videos/{user_id}',
                'stories_thumbnails' => 'stories/thumbnails/{user_id}',
                'stories_temp' => 'stories/temp',
                'avatars' => 'avatars',
                default => $type,
            };
        }

        foreach ($params as $key => $value) {
            $template = str_replace('{'.$key.'}', (string) $value, $template);
        }

        return trim($template, '/');
    }

    /**
     * Stocke une vidéo finalisée ou clip de Reel dans reels/videos/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeReelVideo(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_video_size', 100 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format vidéo non autorisé ({$mime}). Formats supportés : MP4, MOV, WEBM, 3GP, M4V."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["La vidéo dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'mp4';
        $uniqueId = (string) Str::uuid();
        $fileName = "video_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('reels_videos', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'video',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => null,
            'height' => null,
            'duration' => null,
        ];
    }

    /**
     * Stocke un clip de Reel (compatibilité et drafts).
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeReelClip(UploadedFile $file, User $user, ?string $disk = null, bool $isDraft = false): array
    {
        if ($isDraft) {
            return $this->storeReelDraftMedia($file, $user, $disk);
        }

        return $this->storeReelVideo($file, $user, $disk);
    }

    /**
     * Stocke un média temporaire / brouillon pour Studio Reel dans reels/drafts/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeReelDraftMedia(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_video_size', 100 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format vidéo non autorisé ({$mime}). Formats supportés : MP4, MOV, WEBM, 3GP, M4V."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["La vidéo dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'mp4';
        $uniqueId = (string) Str::uuid();
        $fileName = "draft_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('reels_drafts', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'video',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => null,
            'height' => null,
            'duration' => null,
        ];
    }

    /**
     * Stocke une image de couverture pour un Reel dans reels/covers/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int
     * }
     */
    public function storeReelCover(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_image_size', 15 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format d'image non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["L'image dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $uniqueId = (string) Str::uuid();
        $fileName = "cover_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('reels_covers', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        $width = null;
        $height = null;
        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'cover',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Stocke une miniature générée pour un Reel dans reels/thumbnails/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int
     * }
     */
    public function storeReelThumbnail(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format d'image non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP."],
            ]);
        }

        $extension = $file->getClientOriginalExtension() ?: 'jpg';
        $uniqueId = (string) Str::uuid();
        $fileName = "thumb_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('reels_thumbnails', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        $width = null;
        $height = null;
        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'thumbnail',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * Stocke un fichier audio pour le catalogue de sons (sounds/catalogue) ou un son original (sounds/originals/{user_id}).
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string
     * }
     */
    public function storeAudio(UploadedFile $file, ?User $user = null, bool $isOriginal = false, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_audio_size', 30 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_AUDIO_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format audio non autorisé ({$mime}). Formats supportés : MP3, WAV, AAC, OGG, M4A."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["Le fichier audio dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        $extension = $file->getClientOriginalExtension() ?: 'mp3';
        $uniqueId = (string) Str::uuid();
        $fileName = "audio_{$uniqueId}.{$extension}";

        $directory = ($isOriginal && $user)
            ? $this->pathFor('sounds_originals', ['user_id' => $user->id])
            : $this->pathFor('sounds_catalogue');

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'audio',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
        ];
    }

    /**
     * Stocke un média Square dans square/images/{user_id} ou square/videos/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeSquareMedia(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();

        $isImage = in_array($mime, self::ALLOWED_IMAGE_MIMES, true);
        $isVideo = in_array($mime, self::ALLOWED_VIDEO_MIMES, true);

        if (! $isImage && ! $isVideo) {
            throw ValidationException::withMessages([
                'file' => ["Format de fichier non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP, MP4, MOV, WEBM."],
            ]);
        }

        if ($isImage && $size > (int) config('media.max_image_size', 15 * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'file' => ["L'image dépasse la limite maximale autorisée (15 MB)."],
            ]);
        }

        if ($isVideo && $size > (int) config('media.max_video_size', 100 * 1024 * 1024)) {
            throw ValidationException::withMessages([
                'file' => ['La vidéo dépasse la limite maximale autorisée (100 MB).'],
            ]);
        }

        $kind = $isImage ? PostMediaKind::Photo : PostMediaKind::Video;
        $extension = $file->getClientOriginalExtension() ?: ($isImage ? 'jpg' : 'mp4');
        $uniqueId = (string) Str::uuid();
        $fileName = "media_{$uniqueId}.{$extension}";

        $directory = $isImage
            ? $this->pathFor('square_images', ['user_id' => $user->id])
            : $this->pathFor('square_videos', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);

        $width = null;
        $height = null;

        if ($isImage && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => $kind->value,
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
            'duration' => null,
        ];
    }

    /**
     * Stocke un fichier temporaire dans le sous-dossier temp du module concerné.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     disk: string
     * }
     */
    public function storeTempMedia(UploadedFile $file, string $module = 'reels', ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $directory = $this->pathFor("{$module}_temp");

        $extension = $file->getClientOriginalExtension() ?: 'tmp';
        $uniqueId = (string) Str::uuid();
        $fileName = "temp_{$uniqueId}.{$extension}";

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'temp',
            'disk' => $disk,
        ];
    }

    /**
     * Résout l'URL publique accessible pour un chemin de fichier.
     */
    public function getUrl(string $path, ?string $disk = null): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        if (str_starts_with($path, '/storage/')) {
            return $path;
        }

        if (str_starts_with($path, 'storage/')) {
            return '/'.$path;
        }

        if (str_starts_with($path, '/sounds/')) {
            return url($path);
        }

        $disk = $disk ?? $this->getDisk();

        return Storage::disk($disk)->url($path);
    }

    /**
     * Génère une URL pré-signée temporaire pour le stockage distant (S3, R2).
     * En local (disque 'public'), bascule gracieusement sur l'URL publique classique.
     */
    public function getTemporaryUrl(string $path, ?DateTimeInterface $expiration = null, ?string $disk = null): string
    {
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://') || str_starts_with($path, '/storage/')) {
            return $path;
        }

        $disk = $disk ?? $this->getDisk();
        $expiration = $expiration ?? now()->addMinutes((int) config('media.temporary_url_ttl', 60));

        try {
            $driver = Storage::disk($disk);
            if (method_exists($driver, 'temporaryUrl')) {
                return $driver->temporaryUrl($path, $expiration);
            }
        } catch (\Throwable $e) {
            Log::warning("Impossible de générer une URL temporaire sur le disque {$disk}: {$e->getMessage()}");
        }

        return $this->getUrl($path, $disk);
    }

    /**
     * Vérifie si un chemin ou URL correspond à un flux hébergé par un service externe (ex: Cloudflare Stream).
     */
    public function isExternalUrl(string $urlOrPath): bool
    {
        if (! (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://'))) {
            return false;
        }

        $parsed = parse_url($urlOrPath);
        $path = $parsed['path'] ?? '';

        // Si l'URL absolue pointe sur le stockage local /storage/, ce n'est pas un flux externe
        return ! str_starts_with($path, '/storage/');
    }

    /**
     * Vérifie si un fichier existe sur le disque.
     */
    public function exists(string $path, ?string $disk = null): bool
    {
        if ($this->isExternalUrl($path)) {
            return true;
        }

        $disk = $disk ?? $this->getDisk();
        $relativePath = $this->extractRelativePath($path);

        return Storage::disk($disk)->exists($relativePath);
    }

    /**
     * Supprime un fichier stocké (à partir de son chemin relatif ou de son URL).
     * Ne touche pas aux URL de flux externes (Cloudflare Stream, HLS externe).
     */
    public function delete(string $pathOrUrl, ?string $disk = null): bool
    {
        if ($this->isExternalUrl($pathOrUrl)) {
            return false;
        }

        $path = $this->extractRelativePath($pathOrUrl);
        $disk = $disk ?? $this->getDisk();

        if (Storage::disk($disk)->exists($path)) {
            return Storage::disk($disk)->delete($path);
        }

        return false;
    }

    /**
     * Extrait le chemin de fichier relatif à partir d'une URL publique ou d'un chemin.
     */
    public function extractRelativePath(string $urlOrPath): string
    {
        if (str_starts_with($urlOrPath, 'http://') || str_starts_with($urlOrPath, 'https://')) {
            $parsed = parse_url($urlOrPath, PHP_URL_PATH);
            if ($parsed && str_starts_with($parsed, '/storage/')) {
                return substr($parsed, strlen('/storage/'));
            }
            if ($parsed && str_starts_with($parsed, 'storage/')) {
                return substr($parsed, strlen('storage/'));
            }

            return ltrim($parsed ?? $urlOrPath, '/');
        }

        if (str_starts_with($urlOrPath, '/storage/')) {
            return substr($urlOrPath, strlen('/storage/'));
        }

        if (str_starts_with($urlOrPath, 'storage/')) {
            return substr($urlOrPath, strlen('storage/'));
        }

        return ltrim($urlOrPath, '/');
    }

    /**
     * Supprime les médias physiques associés à un Reel lors de sa suppression définitive.
     */
    public function deleteReelMedia(Reel $reel, ?string $disk = null): void
    {
        $disk = $disk ?? $this->getDisk();

        // 1. Vidéo source (uniquement si fichier local hébergé)
        if ($reel->video_url && ! $this->isExternalUrl($reel->video_url)) {
            $this->delete($reel->video_url, $disk);
        }

        // 2. Couverture
        if ($reel->thumbnail_url && ! $this->isExternalUrl($reel->thumbnail_url)) {
            $this->delete($reel->thumbnail_url, $disk);
        }

        // 3. Clips de la composition
        if (! empty($reel->composition['clips']) && is_array($reel->composition['clips'])) {
            foreach ($reel->composition['clips'] as $clip) {
                if (! empty($clip['uri']) && ! $this->isExternalUrl($clip['uri'])) {
                    $this->delete($clip['uri'], $disk);
                }
            }
        }
    }

    /**
     * Initialise l'arborescence des dossiers sur le disque configuré.
     */
    public function ensureDirectoriesExist(?string $disk = null): void
    {
        $disk = $disk ?? $this->getDisk();
        $directories = [
            'reels/videos',
            'reels/covers',
            'reels/thumbnails',
            'reels/drafts',
            'reels/temp',
            'sounds/catalogue',
            'sounds/originals',
            'sounds/temp',
            'square/images',
            'square/videos',
            'square/temp',
            'avatars',
        ];

        foreach ($directories as $dir) {
            if (! Storage::disk($disk)->exists($dir)) {
                Storage::disk($disk)->makeDirectory($dir);
            }
        }
    }

    /**
     * Effectue un audit en lecture seule du stockage physique et compare avec la base de données.
     *
     * @return array{
     *     disk: string,
     *     directories: array<string, int>,
     *     total_files: int,
     *     orphan_files: array<string>
     * }
     */
    public function auditStorage(?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $storage = Storage::disk($disk);

        $directories = [
            'reels/videos',
            'reels/covers',
            'reels/thumbnails',
            'reels/drafts',
            'reels/temp',
            'sounds/catalogue',
            'sounds/originals',
            'sounds/temp',
            'square/images',
            'square/videos',
            'square/temp',
            'avatars',
        ];

        $stats = [];
        $allFiles = [];

        foreach ($directories as $dir) {
            $files = $storage->allFiles($dir);
            // Ignorer les fichiers .gitignore
            $mediaFiles = array_filter($files, fn ($f) => ! str_ends_with($f, '.gitignore'));
            $stats[$dir] = count($mediaFiles);
            $allFiles = array_merge($allFiles, $mediaFiles);
        }

        // Identifier les références existantes en base de données
        $referencedPaths = [];

        try {
            // Reels
            $reelUrls = Reel::query()->pluck('video_url')->filter()->all();
            $reelThumbs = Reel::query()->pluck('thumbnail_url')->filter()->all();
            foreach (array_merge($reelUrls, $reelThumbs) as $url) {
                $referencedPaths[] = $this->extractRelativePath($url);
            }

            // Sounds
            $soundUrls = Sound::query()->pluck('audio_url')->filter()->all();
            foreach ($soundUrls as $url) {
                $referencedPaths[] = $this->extractRelativePath($url);
            }

            // Square post media
            $squareUrls = PostMedia::query()->pluck('media_url')->filter()->all();
            foreach ($squareUrls as $url) {
                $referencedPaths[] = $this->extractRelativePath($url);
            }

            // Users avatars
            $avatarUrls = User::query()->pluck('avatar_url')->filter()->all();
            foreach ($avatarUrls as $url) {
                $referencedPaths[] = $this->extractRelativePath($url);
            }
        } catch (\Throwable $e) {
            Log::info('Audit DB query skipped: '.$e->getMessage());
        }

        $referencedSet = array_flip($referencedPaths);
        $orphans = [];

        foreach ($allFiles as $file) {
            // Un fichier temporaire n'est pas un orphelin
            if (str_contains($file, '/temp/')) {
                continue;
            }

            if (! isset($referencedSet[$file])) {
                $orphans[] = $file;
            }
        }

        return [
            'disk' => $disk,
            'directories' => $stats,
            'total_files' => count($allFiles),
            'orphan_files' => $orphans,
        ];
    }

    /**
     * Nettoie les fichiers temporaires expirés des répertoires dédiés (reels/temp, sounds/temp, square/temp).
     *
     * @return array{
     *     deleted_count: int,
     *     deleted_files: array<string>,
     *     freed_bytes: int
     * }
     */
    public function cleanTemporaryFiles(int $olderThanHours = 24, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $storage = Storage::disk($disk);
        $tempDirs = ['reels/temp', 'sounds/temp', 'square/temp', 'stories/temp'];
        $cutoff = now()->subHours($olderThanHours)->getTimestamp();

        $deletedCount = 0;
        $deletedFiles = [];
        $freedBytes = 0;

        foreach ($tempDirs as $dir) {
            if (! $storage->exists($dir)) {
                continue;
            }

            $files = $storage->allFiles($dir);
            foreach ($files as $file) {
                if (str_ends_with($file, '.gitignore')) {
                    continue;
                }

                $lastModified = $storage->lastModified($file);
                if ($lastModified < $cutoff) {
                    $size = $storage->size($file);
                    if ($storage->delete($file)) {
                        $deletedCount++;
                        $deletedFiles[] = $file;
                        $freedBytes += $size;
                    }
                }
            }
        }

        return [
            'deleted_count' => $deletedCount,
            'deleted_files' => $deletedFiles,
            'freed_bytes' => $freedBytes,
        ];
    }

    /**
     * Stocke un média Story (Photo ou Vidéo, CR3).
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeStoryMedia(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();

        $isImage = in_array($mime, self::ALLOWED_IMAGE_MIMES, true);
        $isVideo = in_array($mime, self::ALLOWED_VIDEO_MIMES, true);

        if ($size === 0 || $size === false) {
            throw ValidationException::withMessages([
                'file' => ['Le fichier média fourni est vide (0 octet).'],
            ]);
        }

        if (! $isImage && ! $isVideo) {
            throw ValidationException::withMessages([
                'file' => ["Format de fichier non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP, MP4, MOV, WEBM, 3GP, M4V."],
            ]);
        }

        // Limites CR3 : Photo max 10 Mo, Vidéo max 50 Mo
        if ($isImage && $size > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ['La photo dépasse la limite maximale autorisée pour une Story (10 Mo).'],
            ]);
        }

        if ($isVideo && $size > 50 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'file' => ['La vidéo dépasse la limite maximale autorisée pour une Story (50 Mo).'],
            ]);
        }

        $type = $isImage ? 'image' : 'video';
        $extension = $file->getClientOriginalExtension() ?: ($isImage ? 'jpg' : 'mp4');
        $uniqueId = (string) Str::uuid();
        $fileName = "story_{$uniqueId}.{$extension}";

        $directory = $isImage
            ? $this->pathFor('stories_images', ['user_id' => $user->id])
            : $this->pathFor('stories_videos', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);

        $width = null;
        $height = null;

        if ($isImage && function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => $type,
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
            'duration' => $isImage ? 5 : null,
        ];
    }

    /**
     * Stocke une photo pour une annonce immobilière dans listings/images/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: null
     * }
     */
    public function storeListingPhoto(UploadedFile $file, User $user, ?string $disk = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_image_size', 15 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format d'image non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["La photo dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg';
        $uniqueId = (string) Str::uuid();
        $fileName = "listing_photo_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('listings_images', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);

        $width = null;
        $height = null;
        if (function_exists('getimagesize')) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $width = $imageInfo[0];
                $height = $imageInfo[1];
            }
        }

        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'image',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
            'duration' => null,
        ];
    }

    /**
     * Stocke une visite vidéo guidée pour une annonce dans listings/videos/{user_id}.
     *
     * @return array{
     *     id: string,
     *     path: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     disk: string,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function storeListingVideo(UploadedFile $file, User $user, ?string $disk = null, ?int $duration = null, ?int $width = null, ?int $height = null): array
    {
        $disk = $disk ?? $this->getDisk();
        $mime = $this->resolveMimeType($file);
        $size = $file->getSize();
        $maxSize = (int) config('media.max_video_size', 100 * 1024 * 1024);

        if (! in_array($mime, self::ALLOWED_VIDEO_MIMES, true)) {
            throw ValidationException::withMessages([
                'file' => ["Format vidéo non autorisé ({$mime}). Formats supportés : MP4, MOV, WEBM, 3GP, M4V."],
            ]);
        }

        if ($size > $maxSize) {
            $maxMb = round($maxSize / (1024 * 1024));
            throw ValidationException::withMessages([
                'file' => ["La vidéo dépasse la taille maximale autorisée ({$maxMb} Mo)."],
            ]);
        }

        if ($duration !== null) {
            if ($duration < 30) {
                throw ValidationException::withMessages([
                    'file' => ['La visite vidéo guidée doit durer au minimum 30 secondes.'],
                ]);
            }
            if ($duration > 180) {
                throw ValidationException::withMessages([
                    'file' => ['La visite vidéo guidée ne peut excéder 3 minutes (180 secondes).'],
                ]);
            }
        }

        if ($width !== null && $height !== null && $height <= $width) {
            throw ValidationException::withMessages([
                'file' => ['La vidéo doit être au format vertical (portrait, ratio 9:16).'],
            ]);
        }

        $extension = $file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'mp4';
        $uniqueId = (string) Str::uuid();
        $fileName = "listing_video_{$uniqueId}.{$extension}";
        $directory = $this->pathFor('listings_videos', ['user_id' => $user->id]);

        $path = $file->storeAs($directory, $fileName, $disk);
        $url = $this->getUrl($path, $disk);

        return [
            'id' => $uniqueId,
            'path' => $path,
            'url' => $url,
            'type' => 'video',
            'mime_type' => $mime,
            'file_size' => $size,
            'disk' => $disk,
            'width' => $width,
            'height' => $height,
            'duration' => $duration,
        ];
    }

    /**
     * Détermine le type MIME réel d'un fichier téléversé.
     */
    protected function resolveMimeType(UploadedFile $file): string
    {
        return $file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream';
    }
}
