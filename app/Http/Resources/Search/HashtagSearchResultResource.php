<?php

namespace App\Http\Resources\Search;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HashtagSearchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'normalized_name' => $this->normalized_name,
            'posts_count' => (int) $this->posts_count,
            'followers_count' => (int) ($this->followers_count ?? 0),
            'is_following' => $this->isFollowedBy($request->user()),
        ];
    }
}
