<?php

namespace App\Http\Resources\Page;

use App\Models\Page\PageMember;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PageMember
 */
class PageMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'page_id' => (int) $this->page_id,
            'user_id' => (int) $this->user_id,
            'role' => $this->role->value,
            'role_label' => $this->role->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'user' => [
                'id' => (int) $this->user?->id,
                'name' => $this->user?->display_name ?? $this->user?->name,
                'handle' => $this->user?->handle,
                'avatar_url' => $this->user?->avatar_url,
            ],
            'page' => $this->whenLoaded('page', function () {
                return [
                    'id' => (int) $this->page->id,
                    'name' => $this->page->name,
                    'handle' => $this->page->handle,
                    'logo_url' => $this->page->logo_url,
                    'sector' => $this->page->sector->value,
                ];
            }),
            'invited_by_id' => $this->invited_by_id,
            'invited_at' => $this->invited_at?->toIso8601String(),
            'accepted_at' => $this->accepted_at?->toIso8601String(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
