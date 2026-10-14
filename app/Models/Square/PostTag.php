<?php

namespace App\Models\Square;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PostTag extends Model
{
    use HasFactory;

    protected $table = 'post_tags';

    protected $fillable = [
        'post_media_id',
        'user_id',
        'x',
        'y',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'x' => 'float',
            'y' => 'float',
        ];
    }

    /**
     * Média photo sur lequel la personne est identifiée.
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(PostMedia::class, 'post_media_id');
    }

    /**
     * Utilisateur identifié.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
