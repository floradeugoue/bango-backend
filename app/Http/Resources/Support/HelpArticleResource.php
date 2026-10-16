<?php

namespace App\Http\Resources\Support;

use App\Models\Support\HelpArticle;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin HelpArticle
 */
class HelpArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'slug' => $this->slug,
            'category' => [
                'value' => $this->category->value,
                'label' => $this->category->label(),
            ],
            'title' => $this->title,
            'content_markdown' => $this->content_markdown,
            'helpful_count' => (int) $this->helpful_count,
            'unhelpful_count' => (int) $this->unhelpful_count,
            'views_count' => (int) $this->views_count,
            'locale' => $this->locale,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
