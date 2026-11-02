<?php

namespace App\Models\Landlord;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandlordUnavailability extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'landlord_unavailabilities';

    protected $fillable = [
        'user_id',
        'from',
        'to',
    ];

    protected function casts(): array
    {
        return [
            'from' => 'datetime',
            'to' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
