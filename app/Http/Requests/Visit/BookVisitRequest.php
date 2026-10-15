<?php

namespace App\Http\Requests\Visit;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class BookVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'slot_id' => ['required', 'string', 'uuid', 'exists:visit_slots,id'],
            'message' => ['nullable', 'string', 'max:1000'],
            'channel' => ['nullable', 'string', 'in:mobile_money,orange_money,mtn_momo'],
            'operator' => ['nullable', 'string', 'max:50'],
            'phone_number' => ['nullable', 'string', 'max:30'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ];
    }
}
