<?php

namespace App\Models\Square;

use App\Enums\Media\MediaProcessingStatus;
use App\Enums\Square\PostMediaKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'media_url',
        'poster_url',
        'media_type',
        'mime_type',
        'file_size',
        'width',
        'height',
        'duration',
        'display_order',
        'edit_metadata',
        'status',
        'error_message',
        'processing_started_at',
        'processed_at',
        'attempts',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => PostMediaKind::class,
            'status' => MediaProcessingStatus::class,
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'display_order' => 'integer',
            'edit_metadata' => 'array',
            'processing_started_at' => 'datetime',
            'processed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Retourne l'URL absolue du média.
     */
    public function getMediaUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        if (str_starts_with($value, '/storage/')) {
            return url($value);
        }

        if (str_starts_with($value, 'storage/')) {
            return url('/'.$value);
        }

        $disk = config('media.disk', 'public');

        return Storage::disk($disk)->url($value);
    }

    /**
     * Retourne l'URL absolue de l'image de couverture / poster.
     */
    public function getPosterUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        if (str_starts_with($value, '/storage/')) {
            return url($value);
        }

        if (str_starts_with($value, 'storage/')) {
            return url('/'.$value);
        }

        $disk = config('media.disk', 'public');

        return Storage::disk($disk)->url($value);
    }

    /**
     * Publication parente.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Personnes identifiées sur ce média photo.
     */
    public function tags(): HasMany
    {
        return $this->hasMany(PostTag::class);
    }

    public function isPending(): bool
    {
        return $this->status === MediaProcessingStatus::Pending;
    }

    public function isProcessing(): bool
    {
        return $this->status === MediaProcessingStatus::Processing;
    }

    public function isCompleted(): bool
    {
        return $this->status === MediaProcessingStatus::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === MediaProcessingStatus::Failed;
    }

    public function canRetry(): bool
    {
        return $this->isFailed();
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => MediaProcessingStatus::Processing,
            'processing_started_at' => now(),
            'attempts' => $this->attempts + 1,
        ]);
    }

    /**
     * @param  array<string, string>|null  $renditions
     */
    public function markAsCompleted(
        ?string $mediaUrl = null,
        ?string $posterUrl = null,
        ?array $renditions = null,
        ?int $duration = null
    ): void {
        $editMeta = $this->edit_metadata ?? [];
        if (! empty($renditions)) {
            $editMeta['renditions'] = $renditions;
        }

        $attributes = [
            'status' => MediaProcessingStatus::Completed,
            'processed_at' => now(),
            'error_message' => null,
            'edit_metadata' => $editMeta,
        ];

        if ($mediaUrl) {
            $attributes['media_url'] = $mediaUrl;
        }
        if ($posterUrl) {
            $attributes['poster_url'] = $posterUrl;
        }
        if ($duration !== null) {
            $attributes['duration'] = $duration;
        }

        $this->update($attributes);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => MediaProcessingStatus::Failed,
            'error_message' => $errorMessage,
        ]);
    }
}
