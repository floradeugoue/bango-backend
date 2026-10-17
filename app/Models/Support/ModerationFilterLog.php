<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationFilterLog extends Model
{
    use HasFactory;

    protected $table = 'moderation_filter_logs';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'content_type',
        'content_id',
        'category',
        'detected_pattern',
        'original_text',
        'action_taken',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
