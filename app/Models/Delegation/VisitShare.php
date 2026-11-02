<?php

namespace App\Models\Delegation;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisitShare extends Model
{
    use HasFactory;

    protected $table = 'visit_shares';

    protected $fillable = [
        'landlord_id',
        'agent_id',
        'agreed_percent',
        'offer_percent',
        'offer_by',
        'offer_at',
    ];

    protected $casts = [
        'agreed_percent' => 'float',
        'offer_percent' => 'float',
        'offer_at' => 'datetime',
    ];

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'agent_id');
    }
}
