<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavedPaymentMethodRequest extends FormRequest
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
            'operator' => ['required', 'string', 'in:mtn,orange,wave,moov,airtel'],
            'phone_number' => ['required', 'string', 'regex:/^[0-9+\s]{6,20}$/'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operator.required' => 'L\'opérateur Mobile Money est obligatoire.',
            'operator.in' => 'Opérateur non pris en charge (opérateurs autorisés : MTN, Orange, Wave, Moov, Airtel).',
            'phone_number.required' => 'Le numéro de téléphone est obligatoire.',
            'phone_number.regex' => 'Le format du numéro de téléphone est invalide.',
        ];
    }
}
