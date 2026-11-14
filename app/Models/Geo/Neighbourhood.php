<?php

namespace App\Models\Geo;

use App\Models\Listing\Listing;
use App\Models\User;
use Database\Factories\Geo\NeighbourhoodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Neighbourhood extends Model
{
    /** @use HasFactory<NeighbourhoodFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_ignored' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'listings_count' => 'integer',
            'users_count' => 'integer',
        ];
    }

    public function getUsersCountAttribute(): int
    {
        return (int) ($this->attributes['users_count'] ?? 0);
    }

    public function getListingsCountAttribute(): int
    {
        return (int) ($this->attributes['listings_count'] ?? 0);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function listings()
    {
        return $this->hasMany(Listing::class, 'neighbourhood', 'name');
    }
}
