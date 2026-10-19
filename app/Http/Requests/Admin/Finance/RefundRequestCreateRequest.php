<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RefundRequestCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.required' => 'Le montant du remboursement est obligatoire.',
            'amount.min' => 'Le montant du remboursement doit être strictement supérieur à zéro.',
            'reason.required' => 'Le motif du remboursement est obligatoire.',
            'reason.min' => 'Le motif doit comporter au moins 5 caractères.',
        ];
    }
}
