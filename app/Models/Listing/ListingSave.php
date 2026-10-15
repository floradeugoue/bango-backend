<?php

namespace App\Models\Listing;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingSave extends Model
{
    use HasFactory;

    protected $table = 'listing_saves';

    protected $fillable = [
        'user_id',
        'listing_id',
    ];

    /**
     * Utilisateur ayant sauvegardé l'annonce.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Annonce sauvegardée.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }
}
