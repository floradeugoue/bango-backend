<?php

namespace App\Http\Requests\Boost;

use Illuminate\Foundation\Http\FormRequest;

class DepositCreditRequest extends FormRequest
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
            'amount' => ['required', 'numeric', 'min:1'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'reason' => ['nullable', 'string', 'min:5', 'max:500'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
