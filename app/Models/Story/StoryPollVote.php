<?php

namespace App\Models\Story;

use App\Models\User;
use Database\Factories\Story\StoryPollVoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryPollVote extends Model
{
    /** @use HasFactory<StoryPollVoteFactory> */
    use HasFactory;

    protected $fillable = [
        'story_slide_id',
        'sticker_id',
        'user_id',
        'option_index',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'option_index' => 'integer',
        ];
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(StorySlide::class, 'story_slide_id');
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(StorySticker::class, 'sticker_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
