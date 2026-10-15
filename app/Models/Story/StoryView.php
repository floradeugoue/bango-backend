<?php

namespace App\Models\Story;

use App\Models\User;
use Database\Factories\Story\StoryViewFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryView extends Model
{
    /** @use HasFactory<StoryViewFactory> */
    use HasFactory;

    protected $fillable = [
        'story_id',
        'story_slide_id',
        'viewer_id',
        'ip_address',
        'viewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'viewed_at' => 'datetime',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function slide(): BelongsTo
    {
        return $this->belongsTo(StorySlide::class, 'story_slide_id');
    }

    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}
