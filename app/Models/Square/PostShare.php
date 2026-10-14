<?php

namespace App\Models\Square;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostShare extends Model
{
    use HasFactory;

    protected $table = 'post_shares';

    protected $fillable = [
        'post_id',
        'user_id',
        'channel',
        'idempotency_key',
    ];

    /**
     * Publication partagée.
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Utilisateur ayant effectué le partage.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
