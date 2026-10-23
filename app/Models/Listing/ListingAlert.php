<?php

namespace App\Models\Listing;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingAlert extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'listing_alerts';

    protected $fillable = [
        'user_id',
        'name',
        'mode',
        'country_code',
        'city',
        'neighbourhoods',
        'kinds',
        'budget_min',
        'budget_max',
        'bedrooms',
        'showers',
        'area_min',
        'area_max',
        'amenities',
        'verified_only',
        'agent_visited_only',
        'utilities_included_only',
        'frequency',
        'push',
        'email',
        'last_triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'neighbourhoods' => 'array',
            'kinds' => 'array',
            'budget_min' => 'decimal:2',
            'budget_max' => 'decimal:2',
            'bedrooms' => 'array',
            'showers' => 'array',
            'area_min' => 'decimal:2',
            'area_max' => 'decimal:2',
            'amenities' => 'array',
            'verified_only' => 'boolean',
            'agent_visited_only' => 'boolean',
            'utilities_included_only' => 'boolean',
            'push' => 'boolean',
            'email' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
