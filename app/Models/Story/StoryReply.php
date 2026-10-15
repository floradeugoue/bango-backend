<?php

namespace App\Models\Story;

use App\Enums\Story\StoryReplyType;
use App\Models\User;
use Database\Factories\Story\StoryReplyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StoryReply extends Model
{
    /** @use HasFactory<StoryReplyFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'story_id',
        'story_slide_id',
        'story_author_id',
        'user_id',
        'type',
        'text',
        'reaction',
        'status',
        'idempotency_key',
        'metadata',
        'future_conversation_id',
        'future_message_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StoryReplyType::class,
            'metadata' => 'array',
        ];
    }

    public function story(): BelongsTo
    {
        return $this->belongsTo(Story::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'story_author_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
