<?php

namespace App\Models\Page;

use App\Enums\Page\PageCertificationStatus;
use App\Models\Payment\PaymentTransaction;
use App\Models\User;
use Database\Factories\Page\PageCertificationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class PageCertification extends Model
{
    /** @use HasFactory<PageCertificationFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'page_certifications';

    public const MAX_ALLOWED_CORRECTIONS = 2; // Règle PG-07

    protected $fillable = [
        'id',
        'page_id',
        'submitted_by_id',
        'status',
        'rccm_number',
        'nif_number',
        'legal_representative_name',
        'legal_representative_id_type',
        'legal_representative_id_number',
        'documents',
        'rejection_reason',
        'supplement_request_reason',
        'corrections_count',
        'payment_transaction_id',
        'decided_by_id',
        'submitted_at',
        'decided_at',
        'history',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PageCertificationStatus::class,
            'documents' => 'array',
            'history' => 'array',
            'submitted_at' => 'datetime',
            'decided_at' => 'datetime',
            'corrections_count' => 'integer',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }

    public function isApproved(): bool
    {
        return $this->status === PageCertificationStatus::Approved;
    }

    public function isPending(): bool
    {
        return $this->status === PageCertificationStatus::Pending;
    }

    public function isSupplementRequested(): bool
    {
        return $this->status === PageCertificationStatus::SupplementRequested;
    }

    public function isRejected(): bool
    {
        return $this->status === PageCertificationStatus::Rejected;
    }

    public function canSubmitCorrection(): bool
    {
        return $this->corrections_count < self::MAX_ALLOWED_CORRECTIONS;
    }

    /**
     * Ajoute une entrée immuable à l'historique du dossier.
     *
     * @param  array<string, mixed>  $extra
     */
    public function recordHistory(string $action, ?int $actorId = null, ?string $reason = null, array $extra = []): void
    {
        $currentHistory = $this->history ?? [];
        $currentHistory[] = array_merge([
            'action' => $action,
            'actor_id' => $actorId,
            'status' => $this->status instanceof PageCertificationStatus ? $this->status->value : $this->status,
            'reason' => $reason,
            'corrections_count' => $this->corrections_count,
            'timestamp' => Carbon::now()->toIso8601String(),
        ], $extra);

        $this->history = $currentHistory;
    }
}
