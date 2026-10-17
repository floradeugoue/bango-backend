<?php

namespace App\Models\Kyc;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $operator_id
 * @property string $kyc_submission_id
 * @property string $kyc_document_id
 * @property string $action
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $created_at
 * @property-read User $operator
 * @property-read KycSubmission $submission
 * @property-read KycDocument $document
 */
class KycDocumentAccessLog extends Model
{
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $table = 'kyc_document_access_logs';

    protected $fillable = [
        'id',
        'operator_id',
        'kyc_submission_id',
        'kyc_document_id',
        'action',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operator_id');
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(KycSubmission::class, 'kyc_submission_id');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(KycDocument::class, 'kyc_document_id');
    }
}
