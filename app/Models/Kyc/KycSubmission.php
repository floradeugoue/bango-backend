<?php

namespace App\Models\Kyc;

use App\Enums\Kyc\KycStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property KycStatus $status
 * @property string|null $full_name
 * @property string|null $id_type
 * @property string|null $id_number
 * @property Carbon|null $expires_on
 * @property string|null $property_right
 * @property string|null $deed_type
 * @property string|null $rejection_reason
 * @property Carbon|null $decided_at
 * @property int|null $decided_by_id
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $user
 * @property-read User|null $decidedBy
 * @property-read Collection<int, KycDocument> $documents
 */
class KycSubmission extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'kyc_submissions';

    protected $fillable = [
        'id',
        'user_id',
        'status',
        'full_name',
        'id_type',
        'id_number',
        'expires_on',
        'property_right',
        'deed_type',
        'rejection_reason',
        'decided_at',
        'decided_by_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => KycStatus::class,
            'expires_on' => 'date',
            'decided_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(KycDocument::class, 'kyc_submission_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Pending->value);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', KycStatus::Approved->value);
    }
}
