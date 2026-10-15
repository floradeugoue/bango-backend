<?php

namespace App\Models\Reel;

use App\Enums\Reel\ReelReactionType;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReelReaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reel_id',
        'user_id',
        'type',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReelReactionType::class,
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
}
