<?php

namespace App\Models\Payment;

use App\Enums\Payment\PaymentAttemptStatus;
use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentEventSource;
use App\Enums\Payment\PaymentProvider;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class PaymentAttempt extends Model
{
    use HasFactory;

    protected $table = 'payment_attempts';

    protected $fillable = [
        'transaction_id',
        'attempt_number',
        'provider',
        'channel',
        'external_reference',
        'status',
        'started_at',
        'finished_at',
        'error_code',
        'error_message',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'attempt_number' => 'integer',
            'provider' => PaymentProvider::class,
            'channel' => PaymentChannel::class,
            'status' => PaymentAttemptStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'transaction_id');
    }

    /**
     * Marque la tentative comme réussie.
     */
    public function markSuccessful(?string $externalReference = null, ?array $metadata = null): self
    {
        $this->status = PaymentAttemptStatus::Successful;
        $this->finished_at = Carbon::now();

        if ($externalReference !== null) {
            $this->external_reference = $externalReference;
        }

        if ($metadata !== null) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();

        $this->transaction->events()->create([
            'event_type' => 'attempt_successful',
            'source' => PaymentEventSource::Gateway->value,
            'from_status' => $this->transaction->status->value,
            'to_status' => $this->transaction->status->value,
            'payload' => [
                'attempt_number' => $this->attempt_number,
                'external_reference' => $this->external_reference,
            ],
        ]);

        return $this;
    }

    /**
     * Marque la tentative comme échouée avec un code d'erreur normalisé et un message expurgé.
     */
    public function markFailed(string $errorCode, ?string $errorMessage = null, ?array $metadata = null): self
    {
        $this->status = PaymentAttemptStatus::Failed;
        $this->finished_at = Carbon::now();
        $this->error_code = $errorCode;
        $this->error_message = $errorMessage;

        if ($metadata !== null) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();

        $this->transaction->events()->create([
            'event_type' => 'attempt_failed',
            'source' => PaymentEventSource::Gateway->value,
            'from_status' => $this->transaction->status->value,
            'to_status' => $this->transaction->status->value,
            'payload' => [
                'attempt_number' => $this->attempt_number,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ],
        ]);

        return $this;
    }

    /**
     * Marque la tentative comme expirée suite à un dépassement du délai de 120s.
     */
    public function markTimeout(?array $metadata = null): self
    {
        $this->status = PaymentAttemptStatus::Timeout;
        $this->finished_at = Carbon::now();
        $this->error_code = 'PAYMENT_TIMEOUT';
        $this->error_message = 'Le délai maximal de 120 secondes est dépassé sans validation USSD.';

        if ($metadata !== null) {
            $this->metadata = array_merge($this->metadata ?? [], $metadata);
        }

        $this->save();

        $this->transaction->events()->create([
            'event_type' => 'attempt_timeout',
            'source' => PaymentEventSource::System->value,
            'from_status' => $this->transaction->status->value,
            'to_status' => $this->transaction->status->value,
            'payload' => [
                'attempt_number' => $this->attempt_number,
            ],
        ]);

        return $this;
    }
}
