<?php

namespace App\Models\Geo;

use Database\Factories\Geo\OperatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    /** @use HasFactory<OperatorFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'prefixes' => 'array',
        ];
    }

    /**
     * Préfixes téléphoniques associés à cet opérateur (ex: ["65", "67", "68"]).
     *
     * @param  mixed  $value
     * @return array<string>
     */
    public function getPrefixesAttribute($value): array
    {
        if (empty($value)) {
            return [];
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;

        return is_array($decoded) ? array_values(array_map('strval', $decoded)) : [];
    }

    /**
     * URL du logo de l'opérateur (résout automatiquement les chemins relatifs vers l'URL absolue).
     */
    public function getLogoUrlAttribute(?string $value): ?string
    {
        if (empty($value)) {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $value;
        }

        return url($value);
    }

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
