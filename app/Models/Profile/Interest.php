<?php

namespace App\Models\Profile;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Interest extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name_fr',
        'name_en',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->name_fr ?? $this->name_en ?? $this->id;
    }
}
