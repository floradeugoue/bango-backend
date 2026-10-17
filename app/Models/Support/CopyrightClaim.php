<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CopyrightClaim extends Model
{
    use HasFactory;

    protected $table = 'copyright_claims';

    protected $fillable = [
        'reference',
        'claimant_id',
        'claimant_name',
        'claimant_email',
        'content_type',
        'content_id',
        'work_title',
        'proof_url',
        'reason',
        'status',
        'decision',
        'decision_notes',
        'resolved_by',
        'resolved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CopyrightClaim $claim) {
            if (empty($claim->reference)) {
                $year = date('Y');
                $random = strtoupper(Str::random(6));
                $claim->reference = "CR-{$year}-{$random}";
            }
        });
    }

    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimant_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
