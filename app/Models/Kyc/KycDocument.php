<?php

namespace App\Models\Kyc;

use App\Enums\Kyc\KycDocKind;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $kyc_submission_id
 * @property int $user_id
 * @property KycDocKind $kind
 * @property string $file_path
 * @property string $original_name
 * @property string $mime_type
 * @property int $file_size
 * @property string $status
 * @property string|null $rejection_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read KycSubmission $submission
 * @property-read User $user
 */
class KycDocument extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'kyc_documents';

    protected $fillable = [
        'id',
        'kyc_submission_id',
        'user_id',
        'kind',
        'file_path',
        'original_name',
        'mime_type',
        'file_size',
        'status',
        'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'kind' => KycDocKind::class,
            'file_size' => 'integer',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
