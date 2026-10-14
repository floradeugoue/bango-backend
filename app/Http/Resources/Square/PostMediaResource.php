<?php

namespace App\Http\Resources\Square;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'url' => $this->media_url,
            'type' => is_object($this->media_type) ? $this->media_type->value : $this->media_type,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'display_order' => (int) $this->display_order,
            'edit_metadata' => $this->edit_metadata,
            'tags' => PostTagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
