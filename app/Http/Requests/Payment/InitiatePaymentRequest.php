<?php

namespace App\Http\Requests\Payment;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentServiceType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class InitiatePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'service_type' => ['sometimes', 'string', 'in:subscription,certification,boost,visit,plan,credit'],
            'kind' => ['sometimes', 'string', 'in:subscription,certification,boost,visit,plan,credit'],
            'price_id' => ['nullable', 'integer', 'exists:subscription_prices,id'],
            'custom_amount' => ['nullable', 'numeric', 'min:100'],
            'amount' => ['nullable', 'numeric'], // Ne sera pas utilisé comme source de vérité, le serveur recalcule
            'phone' => ['required', 'string', 'regex:/^[0-9+\s\-()]{8,25}$/'],
            'operator' => ['nullable', 'string', 'max:64'],
            'idempotency_key' => ['sometimes', 'string', 'max:128'],
            'key' => ['sometimes', 'string', 'max:128'],
            'channel' => ['nullable', 'string', 'in:mobile_money,credit_wallet,card'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $serviceType = $this->getServiceType();

            if ($serviceType === PaymentServiceType::Subscription && empty($this->input('price_id'))) {
                $validator->errors()->add('price_id', 'Le champ price_id est obligatoire pour un abonnement.');
            }

            if ($serviceType === PaymentServiceType::Visit && empty($this->input('custom_amount')) && empty($this->input('amount'))) {
                $validator->errors()->add('custom_amount', 'Le montant est obligatoire pour une visite.');
            }

            if ($serviceType === PaymentServiceType::Credit && empty($this->input('custom_amount')) && empty($this->input('amount'))) {
                $validator->errors()->add('amount', 'Le montant est obligatoire pour un achat de crédits.');
            }

            if (empty($this->getIdempotencyKey())) {
                $validator->errors()->add('idempotency_key', 'Une clé d\'idempotence (idempotency_key ou key) est obligatoire.');
            }
        });
    }

    public function getServiceType(): PaymentServiceType
    {
        $type = $this->input('service_type') ?? $this->input('kind') ?? 'subscription';

        return match ($type) {
            'plan', 'subscription' => PaymentServiceType::Subscription,
            'certification' => PaymentServiceType::Certification,
            'boost' => PaymentServiceType::Boost,
            'visit' => PaymentServiceType::Visit,
            'credit' => PaymentServiceType::Credit,
            default => PaymentServiceType::Subscription,
        };
    }

    public function getIdempotencyKey(): string
    {
        return (string) ($this->input('idempotency_key') ?? $this->input('key') ?? '');
    }

    public function getChannel(): PaymentChannel
    {
        $ch = $this->input('channel') ?? 'mobile_money';

        return PaymentChannel::tryFrom($ch) ?? PaymentChannel::MobileMoney;
    }

    public function getPhone(): string
    {
        return (string) $this->input('phone');
    }

    public function getCustomAmount(): ?float
    {
        $amt = $this->input('custom_amount') ?? $this->input('amount');

        return $amt !== null ? (float) $amt : null;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Les paramètres de la demande de paiement sont invalides.',
            'errors' => $validator->errors()->toArray(),
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => 'Les paramètres de la demande de paiement sont invalides.',
                'details' => $validator->errors()->toArray(),
                'retryable' => false,
            ],
            'meta' => [
                'request_id' => (string) Str::uuid(),
                'timestamp' => now()->toIso8601String(),
            ],
        ], 422));
    }
}
