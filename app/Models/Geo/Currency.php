<?php

namespace App\Models\Geo;

use Database\Factories\Geo\CurrencyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    /** @use HasFactory<CurrencyFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rate_from_xaf' => 'float',
            'round_to' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function countries()
    {
        return $this->hasMany(Country::class);
    }
}
