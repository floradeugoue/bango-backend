<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedPaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'operator',
        'phone_number',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Masque le numéro de téléphone pour la sécurité et la confidentialité (ex: +237 6*****89).
     */
    public function getMaskedPhoneNumberAttribute(): string
    {
        $phone = preg_replace('/[^\d+]/', '', (string) $this->phone_number);
        $length = strlen($phone);

        if ($length <= 4) {
            return $phone;
        }

        $visibleStart = $length > 8 ? 4 : 2;
        $visibleEnd = 2;
        $maskedLength = max(1, $length - $visibleStart - $visibleEnd);

        return substr($phone, 0, $visibleStart).str_repeat('*', $maskedLength).substr($phone, -$visibleEnd);
    }
}
