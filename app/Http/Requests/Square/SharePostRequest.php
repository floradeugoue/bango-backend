<?php

namespace App\Http\Requests\Square;

use Illuminate\Foundation\Http\FormRequest;

class SharePostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'string', 'max:50'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
            'idempotencyKey' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function channel(): ?string
    {
        return $this->input('channel');
    }

    public function idempotencyKey(): ?string
    {
        return $this->input('idempotency_key')
            ?? $this->input('idempotencyKey')
            ?? $this->header('X-Idempotency-Key');
    }
}
