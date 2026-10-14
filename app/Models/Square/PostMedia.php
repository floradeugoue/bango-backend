<?php

namespace App\Models\Square;

use App\Enums\Square\PostMediaKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostMedia extends Model
{
    use HasFactory;

    protected $table = 'post_media';

    protected $fillable = [
        'post_id',
        'media_url',
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
