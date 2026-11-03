<?php

namespace App\Http\Resources\Support;

use App\Models\Support\UserAccountSanction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin UserAccountSanction
 */
class UserSanctionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $meta = is_array($this->metadata) ? $this->metadata : [];

        return [
            'id' => (int) $this->id,
            'type' => $this->type,
            'level' => $this->level !== null ? (int) $this->level : null,
            'starts_at' => $this->starts_at?->toIso8601String(),
            'ends_at' => $this->ends_at?->toIso8601String(),
            'reason' => $this->reason,
            'is_active' => (bool) $this->is_active,
            'lifted_at' => $this->lifted_at?->toIso8601String(),
            'quote' => $meta['quote'] ?? null,
            'where' => $meta['where'] ?? null,
            'rule' => $meta['rule'] ?? null,
            'rule_body' => $meta['rule_body'] ?? null,
            'metadata' => $meta,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
