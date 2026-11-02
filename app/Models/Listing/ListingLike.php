<?php

namespace App\Models\Listing;

use App\Models\Page\Page;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListingLike extends Model
{
    use HasFactory;

    protected $table = 'listing_likes';

    protected $fillable = [
        'user_id',
        'page_id',
        'listing_id',
    ];

    /**
     * Utilisateur ayant aimé l'annonce.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Page ayant aimé l'annonce.
     */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * Annonce aimée.
     */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(Listing::class, 'listing_id');
    }
}
