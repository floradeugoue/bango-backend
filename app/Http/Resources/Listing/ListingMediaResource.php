<?php

namespace App\Http\Resources\Listing;

use App\Models\Listing\ListingMedia;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ListingMedia
 */
class ListingMediaResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'url' => $this->url,
            'thumbnail_url' => $this->thumbnail_url ?? $this->url,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'order' => $this->order,
            'room' => $this->room?->value ?? (string) $this->room,
            'metadata' => $this->metadata,
        ];
    }
}
