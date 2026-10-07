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
}
