<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    /** @use HasFactory<\Database\Factories\Geo\CountryFactory> */
    use HasFactory;
    
    protected $guarded = [];

    public function currency()
    {
        return $this->belongsTo(Currency::class);
    }

    public function cities()
    {
        return $this->hasMany(City::class);
    }

    public function operators()
    {
        return $this->hasMany(Operator::class);
    }
}
