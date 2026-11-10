<?php

namespace App\Models\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReelCommentReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'comment_id',
        'user_id',
        'page_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReelReactionType::class,
        ];
    }

    public function comment(): BelongsTo
    {
        return $this->belongsTo(ReelComment::class, 'comment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
