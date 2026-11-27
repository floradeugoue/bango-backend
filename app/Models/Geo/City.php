<?php

namespace App\Models\Geo;

use Database\Factories\Geo\CityFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    /** @use HasFactory<CityFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'lat' => 'float',
            'lng' => 'float',
            'population' => 'integer',
            'osm_fetched_at' => 'datetime',
        ];
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function neighbourhoods()
    {
        return $this->hasMany(Neighbourhood::class);
    }
}
