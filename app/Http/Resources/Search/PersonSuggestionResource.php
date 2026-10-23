<?php

namespace App\Http\Resources\Search;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonSuggestionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $city = $this->getRawOriginal('city');
        if (! $city && $this->relationLoaded('city') && $this->city) {
            $city = $this->city->name;
        }

        $neighbourhood = $this->getRawOriginal('neighbourhood');
        if (! $neighbourhood && $this->relationLoaded('neighbourhood') && $this->neighbourhood) {
            $neighbourhood = $this->neighbourhood->name;
        }

        return [
            'id' => (string) $this->id,
            'name' => $this->display_name ?? $this->name,
            'handle' => $this->handle ?? '',
            'avatar_url' => $this->avatar_url,
            'is_verified' => (bool) ($this->is_verified ?? false),
            'badge' => $this->badge ?? 'none',
            'city' => $city,
            'neighbourhood' => $neighbourhood,
            'mutual_followers_count' => (int) ($this->mutual_followers_count ?? 0),
        ];
    }
}
