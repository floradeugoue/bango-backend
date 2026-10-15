<?php

namespace App\Models\Story;

use App\Models\User;
use Database\Factories\Story\StoryHighlightFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class StoryHighlight extends Model
{
    /** @use HasFactory<StoryHighlightFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'user_id',
        'title',
        'cover_story_id',
        'cover_media_url',
        'order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function coverStory(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'cover_story_id');
    }

    public function stories(): BelongsToMany
    {
        return $this->belongsToMany(Story::class, 'story_highlight_items', 'highlight_id', 'story_id')
            ->withPivot('order')
            ->orderByPivot('order', 'asc')
            ->withTimestamps();
    }
}
