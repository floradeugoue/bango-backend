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

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function neighbourhoods()
    {
        return $this->hasMany(Neighbourhood::class);
    }
}
