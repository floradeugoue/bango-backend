<?php

namespace App\Http\Requests\Credits;

use App\Enums\Payment\PaymentChannel;
use App\Enums\Payment\PaymentProvider;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class StoreCreditPurchaseRequest extends FormRequest
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
            'amount' => ['required', 'integer', 'min:100', 'max:10000000'],
            'provider' => ['sometimes', 'string', 'in:monetbil,mtn_momo,orange_money'],
            'phone' => ['required', 'string', 'regex:/^[0-9+\s\-()]{8,25}$/'],
            'operator' => ['nullable', 'string', 'in:orange,mtn,ORANGE,MTN'],
            'channel' => ['nullable', 'string', 'in:mobile_money'],
            'idempotency_key' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function getAmount(): int
    {
        return (int) $this->input('amount');
    }

    public function getPhone(): string
    {
        return (string) $this->input('phone');
    }

    public function getOperator(): ?string
    {
        return $this->input('operator') ? strtolower((string) $this->input('operator')) : null;
    }

    public function getProvider(): PaymentProvider
    {
        return PaymentProvider::Monetbil;
    }

    public function getChannel(): PaymentChannel
    {
        return PaymentChannel::MobileMoney;
    }

    public function getIdempotencyKey(): string
    {
        return (string) ($this->input('idempotency_key') ?? $this->header('X-Idempotency-Key') ?? Str::uuid()->toString());
    }
}
