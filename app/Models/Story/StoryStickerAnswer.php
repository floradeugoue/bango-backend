<?php

namespace App\Models\Story;

use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryStickerAnswer extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'story_sticker_answers';

    protected $fillable = [
        'story_id',
        'slide_id',
        'sticker_id',
        'user_id',
        'page_id',
        'type',
        'answer',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class, 'story_id');
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(StorySlide::class, 'slide_id');
    }

    public function sticker(): BelongsTo
    {
        return $this->belongsTo(StorySticker::class, 'sticker_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
