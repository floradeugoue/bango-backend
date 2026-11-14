<?php

namespace App\Http\Resources\Story;

use App\Models\Story\StoryStickerAnswer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin StoryStickerAnswer
 */
class StoryStickerAnswerResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->user;
        $page = $this->page;
        $sticker = $this->sticker;

        $userBadge = $user ? ($user->badge ?? 'none') : 'none';
        $pageBadge = $page ? ($page->isCertified() ? 'page_certified' : 'none') : 'none';

        return [
            'id' => (string) $this->id,
            'story_id' => (string) $this->story_id,
            'slide_id' => (string) $this->slide_id,
            'sticker_id' => (string) $this->sticker_id,
            'type' => $this->type,
            'answer' => $this->answer,
            'user' => $user ? [
                'id' => (int) $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name ?: $user->name,
                'handle' => $user->handle,
                'avatar_url' => $user->avatar_url,
                'is_verified' => (bool) $user->is_verified,
                'badge' => $userBadge,
            ] : null,
            'page' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'handle' => $page->handle,
                'logo_url' => $page->logo_url,
                'certification_status' => $page->certification_status?->value ?? (string) $page->certification_status,
                'is_certified' => $page->isCertified(),
                'badge' => $pageBadge,
            ] : null,
            'author' => $page ? [
                'id' => (int) $page->id,
                'name' => $page->name,
                'display_name' => $page->name,
                'handle' => $page->handle,
                'avatar_url' => $page->logo_url,
                'is_verified' => $page->isCertified(),
                'badge' => $pageBadge,
                'is_page' => true,
            ] : ($user ? [
                'id' => (int) $user->id,
                'name' => $user->name,
                'display_name' => $user->display_name ?: $user->name,
                'handle' => $user->handle,
                'avatar_url' => $user->avatar_url,
                'is_verified' => (bool) $user->is_verified,
                'badge' => $userBadge,
                'is_page' => false,
            ] : null),
            'sticker' => $sticker ? [
                'id' => (string) $sticker->id,
                'type' => is_object($sticker->type) ? $sticker->type->value : $sticker->type,
                'question' => $sticker->data['question'] ?? ($sticker->data['text'] ?? null),
                'data' => $sticker->data,
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            'answered_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
