<?php

namespace App\Models\Housing;
 
use App\Models\User;

use App\Enums\HousingIntent;
use App\Models\Geo\City;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
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
        return $this->belongsTo(Currency::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function neighbourhoods()
    {
        return $this->belongsToMany(Neighbourhood::class, 'housing_search_neighbourhood');
    }
}
