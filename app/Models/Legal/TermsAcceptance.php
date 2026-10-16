<?php

namespace App\Models\Legal;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TermsAcceptance extends Model
{
    use HasFactory;

    protected $table = 'terms_acceptances';

    protected $fillable = [
        'user_id',
        'legal_document_id',
        'version',
        'document_slug',
        'locale',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * Utilisateur ayant consenti.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Document légal associé si référencé.
     */
    public function legalDocument(): BelongsTo
    {
        return $this->belongsTo(LegalDocument::class, 'legal_document_id');
    }
}
