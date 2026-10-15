<?php

namespace App\Models\Story;

use App\Models\User;
use Database\Factories\Story\StoryReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReport extends Model
{
    /** @use HasFactory<StoryReportFactory> */
    use HasFactory;

    protected $fillable = [
        'story_id',
        'reporter_id',
        'reason',
        'details',
        'status',
    ];

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }
}
