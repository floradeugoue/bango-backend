<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class PaymentDispute extends Model
{
    use HasFactory;

    protected $table = 'payment_disputes';

    protected $fillable = [
        'reference',
        'transaction_id',
        'user_id',
        'type',
        'status',
        'reason',
        'claimant_phone',
        'operator_reference',
        'disputed_amount',
        'currency',
        'assigned_to',
        'resolution_decision',
        'notes',
        'metadata',
        'resolved_at',
        'closed_at',
    ];

    protected function casts(): array
    {
        return [
            'disputed_amount' => 'decimal:2',
            'notes' => 'array',
            'metadata' => 'array',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentDispute $dispute): void {
            if (empty($dispute->reference)) {
                $prefix = $dispute->type === 'double_charge' ? 'DCC' : 'DSP';
                $dispute->reference = sprintf(
                    'BNG-%s-%s-%s',
                    $prefix,
                    Carbon::now()->format('Ymd'),
                    strtoupper(Str::random(6))
                );
            }
        });
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Ajoute une note d'investigation immuable à l'historique du dossier.
     */
    public function addNote(User $author, string $note): self
    {
        $existingNotes = $this->notes ?? [];
        $existingNotes[] = [
            'id' => (string) Str::uuid(),
            'author_id' => $author->id,
            'author_name' => $author->name,
            'note' => $note,
            'created_at' => Carbon::now()->toIso8601String(),
        ];

        $this->notes = $existingNotes;
        $this->save();

        return $this;
    }

    /**
     * Fait évoluer le statut du dossier avec enregistrement de la décision et de l'acteur.
     */
    public function transitionTo(string $targetStatus, User $actor, ?string $decision = null): self
    {
        $oldStatus = $this->status;
        $this->status = $targetStatus;

        if ($decision !== null) {
            $this->resolution_decision = $decision;
        }

        if (in_array($targetStatus, ['resolved', 'rejected'], true)) {
            $this->resolved_at = Carbon::now();
        }

        if ($targetStatus === 'closed') {
            $this->closed_at = Carbon::now();
        }

        $this->save();

        $this->addNote(
            author: $actor,
            note: sprintf(
                'Statut du dossier passé de "%s" à "%s".%s',
                $oldStatus,
                $targetStatus,
                $decision ? " Décision : {$decision}" : ''
            )
        );

        return $this;
    }
}
