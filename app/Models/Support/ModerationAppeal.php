<?php

namespace App\Models\Support;

use App\Enums\Support\AppealStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ModerationAppeal extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'user_id',
        'sanction_type',
        'sanction_reference',
        'sanction_operator_id',
        'reason',
        'explanation',
        'contact_email',
        'status',
        'resolution',
        'decision_notes',
        'reviewed_at',
        'reviewed_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => AppealStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ModerationAppeal $appeal) {
            if (empty($appeal->reference)) {
                $year = date('Y');
                $random = strtoupper(Str::random(6));
                $appeal->reference = "APP-{$year}-{$random}";
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function sanctionOperator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sanction_operator_id');
    }
}
