<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Neighbourhood extends Model
{
    /** @use HasFactory<\Database\Factories\Geo\NeighbourhoodFactory> */
    use HasFactory;
    
    protected $guarded = [];

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
