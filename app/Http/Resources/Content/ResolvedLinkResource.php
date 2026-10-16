<?php

namespace App\Http\Resources\Content;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ResolvedLinkResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'is_valid' => $this->resource['is_valid'] ?? true,
            'target_type' => $this->resource['target_type'],
            'target_id' => $this->resource['target_id'],
            'status' => $this->resource['status'],
            'requires_auth' => (bool) ($this->resource['requires_auth'] ?? false),
            'is_accessible' => (bool) ($this->resource['is_accessible'] ?? true),
            'metadata' => $this->resource['metadata'] ?? [],
            'short_link' => $this->resource['short_link'] ?? null,
        ];
    }
}
