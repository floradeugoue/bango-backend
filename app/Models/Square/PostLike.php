<?php

namespace App\Models\Square;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostLike extends Model
{
    use HasFactory;

    protected $table = 'post_likes';

    protected $fillable = [
        'post_id',
        'user_id',
    ];

    /**
     * Publication aimée.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Utilisateur ayant aimé la publication.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
