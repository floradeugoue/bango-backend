<?php

namespace App\Http\Resources\Legal;

use App\Models\Legal\LegalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LegalDocument
 */
class LegalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'version' => $this->version,
            'locale' => $this->locale,
            'title' => $this->title,
            'content_markdown' => $this->content_markdown,
            'effective_date' => $this->effective_date?->toIso8601String(),
            'is_mandatory' => (bool) $this->is_mandatory,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
