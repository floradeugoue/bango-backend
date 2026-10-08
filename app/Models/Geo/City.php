<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class City extends Model
{
    /** @use HasFactory<\Database\Factories\Geo\CityFactory> */
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
