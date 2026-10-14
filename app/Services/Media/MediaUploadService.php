<?php

namespace App\Services\Media;

use App\Enums\Square\PostMediaKind;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaUploadService
{
    /**
     * Types MIME images autorisés.
     *
     * @var array<string>
     */
    protected const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /**
     * Types MIME vidéos autorisés.
     *
     * @var array<string>
     */
    protected const ALLOWED_VIDEO_MIMES = [
        'video/mp4',
        'video/quicktime',
    ];

    /**
     * Taille max pour images : 15 MB.
     */
    public const MAX_IMAGE_BYTES = 15 * 1024 * 1024;

    /**
     * Taille max pour vidéos : 100 MB.
     */
    public const MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    /**
     * Téléverse et enregistre un média Square.
     *
     * @return array{
     *     id: string,
     *     url: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function upload(UploadedFile $file, User $user, string $disk = 'public'): array
    {
        $mime = $file->getMimeType() ?? $file->getClientMimeType() ?? 'application/octet-stream';
        $size = $file->getSize();

        $isImage = in_array($mime, self::ALLOWED_IMAGE_MIMES, true);
        $isVideo = in_array($mime, self::ALLOWED_VIDEO_MIMES, true);

        if (! $isImage && ! $isVideo) {
            throw new InvalidArgumentException("Format de fichier non autorisé ({$mime}). Formats supportés : JPEG, PNG, WEBP, MP4, MOV.");
        }

        if ($isImage && $size > self::MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException('La taille de l\'image dépasse la limite maximale autorisée (15 MB).');
        }

        if ($isVideo && $size > self::MAX_VIDEO_BYTES) {
            throw new InvalidArgumentException('La taille de la vidéo dépasse la limite maximale autorisée (100 MB).');
        }

        $kind = $isImage ? PostMediaKind::Photo : PostMediaKind::Video;
        $extension = $file->getClientOriginalExtension() ?: ($isImage ? 'jpg' : 'mp4');
        $uniqueId = (string) Str::uuid();
        $fileName = "media_{$uniqueId}.{$extension}";
        $directory = "square/{$user->id}";

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

        $url = Storage::disk($disk)->url($path);

        return [
            'id' => $uniqueId,
            'url' => $url,
            'type' => $kind->value,
            'mime_type' => $mime,
            'file_size' => $size,
            'width' => $width,
            'height' => $height,
            'duration' => null,
        ];
    }
}
