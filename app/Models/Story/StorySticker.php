<?php

namespace App\Models\Story;

use App\Enums\Story\StoryStickerType;
use Database\Factories\Story\StoryStickerFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorySticker extends Model
{
    /** @use HasFactory<StoryStickerFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'story_slide_id',
        'type',
        'x',
        'y',
        'scale',
        'rotation',
        'data',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StoryStickerType::class,
            'x' => 'float',
            'y' => 'float',
            'scale' => 'float',
            'rotation' => 'float',
            'data' => 'array',
        ];
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(StorySlide::class, 'story_slide_id');
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(StoryPollVote::class, 'sticker_id');
    }
}
