<?php

namespace App\Http\Requests\Payment;

use App\Enums\Payment\PaymentServiceType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class GetQuoteRequest extends FormRequest
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
            'service_type' => ['nullable', 'string', 'in:subscription,certification,boost,visit,plan,credit'],
            'kind' => ['nullable', 'string', 'in:subscription,certification,boost,visit,plan,credit'],
            'price_id' => ['nullable', 'integer', 'exists:subscription_prices,id'],
            'custom_amount' => ['nullable', 'numeric', 'min:100'],
            'amount' => ['nullable', 'numeric', 'min:100'],
            'duration_days' => ['nullable', 'integer', 'min:1'],
            'gateway' => ['nullable', 'string', 'in:monetbil'],
        ];
    }

    public function getServiceType(): PaymentServiceType
    {
        $raw = strtolower((string) ($this->input('service_type') ?? $this->input('kind') ?? 'subscription'));

        if ($raw === 'plan') {
            return PaymentServiceType::Subscription;
        }

        return PaymentServiceType::tryFrom($raw) ?? PaymentServiceType::Subscription;
    }

    public function getCustomAmount(): ?float
    {
        $val = $this->input('custom_amount') ?? $this->input('amount');

        return $val !== null ? (float) $val : null;
    }

    public function getGateway(): string
    {
        return (string) $this->input('gateway', config('payment.default_gateway', 'monetbil'));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Les paramètres du devis sont invalides.',
            'errors' => $validator->errors()->toArray(),
            'error' => [
                'code' => 'INVALID_INPUT',
                'message' => 'Les paramètres du devis sont invalides.',
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
