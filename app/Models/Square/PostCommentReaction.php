<?php

namespace App\Models\Square;

use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostCommentReaction extends Model
{
    use HasFactory;

    protected $table = 'post_comment_reactions';

    protected $fillable = [
        'comment_id',
        'user_id',
        'page_id',
        'type',
    ];

    /**
     * Commentaire ciblé par la réaction.
     */
    public function comment(): BelongsTo
    {
        return $this->belongsTo(PostComment::class, 'comment_id');
    }

    /**
     * Utilisateur ayant réagi.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Page professionnelle au nom de laquelle la réaction a été émise.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }
}
