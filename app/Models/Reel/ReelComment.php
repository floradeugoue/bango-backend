<?php

namespace App\Models\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ReelComment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'reel_id',
        'user_id',
        'parent_id',
        'body',
        'likes_count',
        'dislikes_count',
        'replies_count',
        'is_edited',
    ];

    protected function casts(): array
    {
        return [
            'likes_count' => 'integer',
            'dislikes_count' => 'integer',
            'replies_count' => 'integer',
            'is_edited' => 'boolean',
        ];
    }

    public function reel(): BelongsTo
    {
        return $this->belongsTo(Reel::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('created_at');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ReelCommentReaction::class, 'comment_id');
    }

    public function isLikedBy(User $user): bool
    {
        return $this->reactions()
            ->where('user_id', $user->id)
            ->where('type', ReelReactionType::Like->value)
            ->exists();
    }

    public function isDislikedBy(User $user): bool
    {
        return $this->reactions()
            ->where('user_id', $user->id)
            ->where('type', ReelReactionType::Dislike->value)
            ->exists();
    }
}
