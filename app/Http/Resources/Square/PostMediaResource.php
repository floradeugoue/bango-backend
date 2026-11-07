<?php

namespace App\Http\Resources\Square;

use App\Models\Reel\Sound;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class PostMediaResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $soundMeta = null;
        if (! empty($this->edit_metadata['sound']) && is_array($this->edit_metadata['sound'])) {
            $soundRaw = $this->edit_metadata['sound'];
            $soundId = $soundRaw['id'] ?? $soundRaw['sound_id'] ?? $soundRaw['soundId'] ?? null;
            $soundModel = $soundId ? Sound::find($soundId) : null;
            $audioUrl = $soundModel?->audio_url
                ? (str_starts_with($soundModel->audio_url, 'http') ? $soundModel->audio_url : Storage::disk('public')->url($soundModel->audio_url))
                : ($soundRaw['audio_url'] ?? $soundRaw['audioUrl'] ?? null);

            $startMs = (int) ($soundRaw['start_ms'] ?? $soundRaw['startMs'] ?? 0);

            $soundMeta = [
                'id' => (string) $soundId,
                'sound_id' => (string) $soundId,
                'soundId' => (string) $soundId,
                'title' => $soundModel?->title ?? ($soundRaw['title'] ?? 'Son sélectionné'),
                'artist' => $soundModel?->artist ?? ($soundRaw['artist'] ?? null),
                'audio_url' => $audioUrl,
                'audioUrl' => $audioUrl,
                'start_ms' => $startMs,
                'startMs' => $startMs,
            ];
        }

        return [
            'id' => (string) $this->id,
            'url' => $this->media_url,
            'poster_url' => $this->poster_url,
            'posterUrl' => $this->poster_url,
            'type' => is_object($this->media_type) ? $this->media_type->value : $this->media_type,
            'mime_type' => $this->mime_type,
            'file_size' => $this->file_size,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration !== null ? (int) $this->duration : null,
            'sound' => $soundMeta,
            'renditions' => $this->edit_metadata['renditions'] ?? null,
            'status' => is_object($this->status) ? $this->status->value : ($this->status ?? 'completed'),
            'error_message' => $this->error_message,
            'display_order' => (int) $this->display_order,
            'edit_metadata' => $this->edit_metadata,
            'tags' => PostTagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
