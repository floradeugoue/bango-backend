<?php

namespace App\Models;

use App\Enums\HousingIntent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HousingSearch extends Model
{
    protected $fillable = [
        'user_id',
        'intent',
        'types',
        'budget',
        'currency',
        'city',
        'neighbourhoods',
        'place',
    ];

    protected function casts(): array
    {
        return [
            'intent' => HousingIntent::class,
            'types' => 'array',
            'budget' => 'array',
            'neighbourhoods' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency()
    {
        return $this->belongsTo(\App\Models\Geo\Currency::class);
    }

    public function city()
    {
        return $this->belongsTo(\App\Models\Geo\City::class);
    }

    public function neighbourhoods()
    {
        return $this->belongsToMany(\App\Models\Geo\Neighbourhood::class, 'housing_search_neighbourhood');
    }
}
