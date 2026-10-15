<?php

namespace App\Models\Story;

use App\Enums\Story\StoryMediaType;
use App\Models\Reel\Sound;
use Database\Factories\Story\StorySlideFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorySlide extends Model
{
    /** @use HasFactory<StorySlideFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'story_id',
        'media_type',
        'media_path',
        'media_url',
        'thumbnail_path',
        'thumbnail_url',
        'filter',
        'caption',
        'duration',
        'order',
        'sound_id',
        'sound_metadata',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'media_type' => StoryMediaType::class,
            'duration' => 'integer',
            'order' => 'integer',
            'sound_metadata' => 'array',
            'metadata' => 'array',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function stickers(): HasMany
    {
        return $this->hasMany(StorySticker::class);
    }

    public function pollVotes(): HasMany
    {
        return $this->hasMany(StoryPollVote::class);
    }

    public function sound(): BelongsTo
    {
        return $this->belongsTo(Sound::class);
    }
}
