<?php

namespace App\Models\Landlord;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordSlotRule extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'landlord_slot_rules';

    protected $fillable = [
        'user_id',
        'days',
        'minimum_hours',
        'grouped',
        'group_size',
        'auto_confirm',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'array',
            'minimum_hours' => 'integer',
            'grouped' => 'boolean',
            'group_size' => 'integer',
            'auto_confirm' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
