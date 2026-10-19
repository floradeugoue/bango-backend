<?php

namespace App\Models\Payment;

use App\Models\User;
use App\Models\Visit\VisitReservation;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $reference
 * @property int $landlord_id
 * @property string|null $visit_reservation_id
 * @property int|null $transaction_id
 * @property float $amount
 * @property string $currency
 * @property string $status
 * @property Carbon $scheduled_at
 * @property Carbon|null $executed_at
 * @property string $provider
 * @property string $channel
 * @property string|null $external_reference
 * @property int $attempts_count
 * @property string|null $last_error
 * @property array|null $metadata
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read User $landlord
 * @property-read VisitReservation|null $visitReservation
 * @property-read PaymentTransaction|null $transaction
 */
class LandlordPayout extends Model
{
    use HasFactory;

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'landlord_payouts';

    protected $fillable = [
        'reference',
        'landlord_id',
        'visit_reservation_id',
        'transaction_id',
        'amount',
        'currency',
        'status',
        'scheduled_at',
        'executed_at',
        'provider',
        'channel',
        'external_reference',
        'attempts_count',
        'last_error',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'scheduled_at' => 'datetime',
            'executed_at' => 'datetime',
            'attempts_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function landlord(): BelongsTo
    {
        return $this->belongsTo(User::class, 'landlord_id');
    }

    public function visitReservation(): BelongsTo
    {
        return $this->belongsTo(VisitReservation::class, 'visit_reservation_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function canRetry(): bool
    {
        return in_array($this->status, [self::STATUS_FAILED, self::STATUS_SCHEDULED], true);
    }
}
