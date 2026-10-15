<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryView
 */
class StoryViewerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $viewer = $this->viewer;

        return [
            'id' => $viewer->id,
            'name' => $viewer->name,
            'handle' => $viewer->handle,
            'display_name' => $viewer->display_name ?? $viewer->name,
            'avatar_url' => $viewer->avatar_url,
            'is_verified' => (bool) $viewer->is_verified,
            'viewed_at' => $this->viewed_at?->toIso8601String() ?? $this->created_at?->toIso8601String(),
        ];
    }
}
