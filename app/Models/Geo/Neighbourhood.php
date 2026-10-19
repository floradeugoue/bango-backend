<?php

namespace App\Models\Geo;

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
            'is_ignored' => 'boolean',
        ];
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }
}
