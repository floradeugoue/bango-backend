<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AdminApproval extends Model
{
    use HasFactory;

    protected $table = 'admin_approvals';

    protected $fillable = [
        'requester_id',
        'validator_id',
        'action',
        'approvable_type',
        'approvable_id',
        'payload',
        'status',
        'decision_reason',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validator_id');
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Vérifie si un opérateur donné peut valider cette demande (principe des 4 yeux).
     */
    public function canBeValidatedBy(User $operator): bool
    {
        if ($this->status !== 'pending') {
            return false;
        }

        // L'opérateur ne peut pas valider sa propre demande
        return (int) $this->requester_id !== (int) $operator->id;
    }
}
