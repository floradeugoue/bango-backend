<?php

namespace App\Models\Listing;

use App\Enums\Listing\ListingPhotoRoom;
use Database\Factories\Listing\ListingMediaFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingMedia extends Model
{
    /** @use HasFactory<ListingMediaFactory> */
    use HasFactory, HasUuids;

    protected $table = 'listing_media';

    protected $fillable = [
        'id',
        'listing_id',
        'type',
        'path',
        'url',
        'thumbnail_path',
        'thumbnail_url',
        'mime_type',
        'file_size',
        'width',
        'height',
        'duration',
        'order',
        'room',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'duration' => 'integer',
            'order' => 'integer',
            'file_size' => 'integer',
            'room' => ListingPhotoRoom::class,
            'metadata' => 'array',
        ];
    }

    /**
     * Annonce à laquelle appartient ce média.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }
}
