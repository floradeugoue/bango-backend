<?php

namespace App\Http\Resources\Square;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PostTagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'accountId' => (string) $this->user_id,
            'account_id' => (string) $this->user_id,
            'handle' => $this->user?->handle ?? '',
            'name' => $this->user?->display_name ?? $this->user?->name ?? '',
            'avatar' => $this->user?->avatar_url,
            'x' => (float) $this->x,
            'y' => (float) $this->y,
        ];
    }
}
