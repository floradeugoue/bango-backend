<?php

namespace App\Models\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModerationRule extends Model
{
    use HasFactory;

    protected $table = 'moderation_rules';

    protected $fillable = [
        'code',
        'name',
        'description',
        'app_reason',
        'severity',
        'default_action',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected $appends = [
        'stats',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Statistiques consolidées de la règle (signalements du mois, précédent, en attente, taux de recours/annulation).
     *
     * @return array{this_month: int, prev_month: int, pending: int, appeal_rate: float, overturn_rate: float}
     */
    public function getStatsAttribute($value = null): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (isset($this->attributes['stats']) && is_array($this->attributes['stats'])) {
            return $this->attributes['stats'];
        }

        return [
            'this_month' => 0,
            'prev_month' => 0,
            'pending' => 0,
            'appeal_rate' => 0.0,
            'overturn_rate' => 0.0,
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
