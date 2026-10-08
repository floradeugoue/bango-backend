<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    /** @use HasFactory<\Database\Factories\Geo\CurrencyFactory> */
    use HasFactory;
    
    protected $guarded = [];

    public function countries()
    {
        return $this->hasMany(Country::class);
    }
}
