<?php

namespace App\Http\Resources\Search;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PeopleSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->display_name ?? $this->name,
            'handle' => $this->handle ?? '',
            'avatar' => $this->avatar_url,
            'avatar_url' => $this->avatar_url,
            'verified' => (bool) ($this->is_verified ?? false),
            'badge' => $this->badge ?? 'none',
        ];
    }
}
