<?php

namespace App\Models\Support;

use App\Models\Square\Hashtag;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HashtagExclusion extends Model
{
    use HasFactory;

    protected $table = 'hashtag_exclusions';

    protected $fillable = [
        'hashtag_id',
        'hashtag_name',
        'reason',
        'excluded_by',
        'is_active',
        'restored_by',
        'restored_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'restored_at' => 'datetime',
        ];
    }

    public function hashtag(): BelongsTo
    {
        return $this->belongsTo(Hashtag::class);
    }

    public function excluder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'excluded_by');
    }

    public function restorer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'restored_by');
    }
}
