<?php

namespace App\Models\Story;

use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryLike extends Model
{
    use HasFactory;

    protected $fillable = [
        'story_id',
        'user_id',
        'page_id',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'page_id');
    }
}
