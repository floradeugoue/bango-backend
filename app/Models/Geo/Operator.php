<?php

namespace App\Models\Geo;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    /** @use HasFactory<\Database\Factories\Geo\OperatorFactory> */
    use HasFactory;
    
    protected $guarded = [];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
