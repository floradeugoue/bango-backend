<?php

namespace App\Services\Media;

use App\Models\User;
use Illuminate\Http\UploadedFile;

class MediaUploadService
{
    public function __construct(
        protected MediaStorageService $storageService
    ) {}

    /**
     * Téléverse et enregistre un média Square dans square/images/{user_id} ou square/videos/{user_id}.
     *
     * @param  ?string  $disk  Disque de stockage (null pour utiliser le disque par défaut configuré, ex: R2)
     * @return array{
     *     id: string,
     *     url: string,
     *     path: string,
     *     type: string,
     *     mime_type: string,
     *     file_size: int,
     *     width: ?int,
     *     height: ?int,
     *     duration: ?int
     * }
     */
    public function upload(UploadedFile $file, User $user, ?string $disk = null): array
    {
        return $this->storageService->storeSquareMedia($file, $user, $disk);
    }
}
