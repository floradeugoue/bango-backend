<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDailyActivity extends Model
{
    use HasFactory;

    protected $table = 'user_daily_activity';

    protected $fillable = [
        'user_id',
        'date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = $value instanceof \DateTimeInterface
            ? $value->format('Y-m-d')
            : substr((string) $value, 0, 10);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
