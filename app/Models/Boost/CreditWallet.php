<?php

namespace App\Models\Boost;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CreditWallet extends Model
{
    use HasFactory;

    protected $table = 'credit_wallets';

    protected $fillable = [
        'user_id',
        'balance',
        'currency',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'balance' => 'integer',
        ];
    }

    /**
     * Utilisateur propriétaire du portefeuille de crédits.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Historique immuable des transactions de crédits (grand livre).
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class, 'wallet_id')->orderByDesc('created_at');
    }

    /**
     * Vérifie si le solde disponible est suffisant pour couvrir le montant requis.
     */
    public function canAfford(string|float|int $amount): bool
    {
        return (float) $this->balance >= (float) $amount;
    }
}
