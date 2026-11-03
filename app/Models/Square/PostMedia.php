<?php

namespace App\Models\Square;

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
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => PostMediaKind::class,
            'file_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'display_order' => 'integer',
            'edit_metadata' => 'array',
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

        return Storage::disk('public')->url($value);
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

        return Storage::disk('public')->url($value);
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
}
