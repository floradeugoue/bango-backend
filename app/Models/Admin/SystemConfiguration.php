<?php

namespace App\Models\Admin;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemConfiguration extends Model
{
    use HasFactory;

    protected $table = 'system_configurations';

    protected $fillable = [
        'key',
        'group',
        'value',
        'type',
        'description',
        'is_public',
        'updated_by_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => 'json',
            'is_public' => 'boolean',
        ];
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
    }

    /**
     * Récupère la valeur typée d'une configuration par sa clé.
     */
    public static function getValue(string $key, mixed $default = null): mixed
    {
        $config = static::where('key', $key)->first();
        if (! $config) {
            return $default;
        }

        return $config->typedValue();
    }

    /**
     * Définit ou met à jour une configuration.
     */
    public static function setValue(string $key, mixed $value, string $group = 'system', string $type = 'string', ?string $description = null, ?User $operator = null): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            [
                'group' => $group,
                'value' => $value,
                'type' => $type,
                'description' => $description,
                'updated_by_id' => $operator?->id,
            ]
        );
    }

    /**
     * Retourne la valeur avec le casting PHP approprié.
     */
    public function typedValue(): mixed
    {
        $val = $this->value;

        return match ($this->type) {
            'integer', 'int' => is_numeric($val) ? (int) $val : $val,
            'float', 'double' => is_numeric($val) ? (float) $val : $val,
            'boolean', 'bool' => filter_var($val, FILTER_VALIDATE_BOOLEAN),
            'array', 'json' => (array) $val,
            default => (string) (is_array($val) ? json_encode($val) : $val),
        };
    }
}
