<?php

namespace Database\Factories\Listing;

use App\Enums\Listing\ListingPhotoRoom;
use App\Models\Listing\Listing;
use App\Models\Listing\ListingMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ListingMedia>
 */
class ListingMediaFactory extends Factory
{
    protected $model = ListingMedia::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $id = (string) Str::uuid();

        return [
            'id' => $id,
            'listing_id' => Listing::factory(),
            'type' => 'image',
            'path' => "listings/images/1/photo_{$id}.jpg",
            'url' => "https://storage.bango.app/listings/images/1/photo_{$id}.jpg",
            'thumbnail_path' => "listings/thumbnails/1/photo_{$id}.jpg",
            'thumbnail_url' => "https://storage.bango.app/listings/thumbnails/1/photo_{$id}.jpg",
            'mime_type' => 'image/jpeg',
            'file_size' => 1024 * 1024 * 2,
            'width' => 1920,
            'height' => 1080,
            'duration' => null,
            'order' => 0,
            'room' => ListingPhotoRoom::Living,
            'metadata' => null,
        ];
    }

    public function video(): static
    {
        $id = (string) Str::uuid();

        return $this->state(fn (array $attributes) => [
            'id' => $id,
            'type' => 'video',
            'path' => "listings/videos/1/video_{$id}.mp4",
            'url' => "https://storage.bango.app/listings/videos/1/video_{$id}.mp4",
            'mime_type' => 'video/mp4',
            'width' => 1080,
            'height' => 1920,
            'duration' => 90,
            'room' => null,
        ]);
    }
}
