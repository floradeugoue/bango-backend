<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryReply;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryReply
 */
class StoryReplyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'story_id' => (string) $this->story_id,
            'type' => $this->type->value,
            'text' => $this->text,
            'reaction' => $this->reaction,
            'status' => $this->status,
            'idempotency_key' => $this->idempotency_key,
            'story_preview_card' => $this->metadata,
            'message' => 'Interaction Story enregistrée avec succès pour intégration future avec la messagerie.',
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
