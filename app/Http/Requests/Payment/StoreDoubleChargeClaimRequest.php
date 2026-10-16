<?php

namespace App\Http\Requests\Payment;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreDoubleChargeClaimRequest extends FormRequest
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
            'transaction_id' => ['nullable', 'integer', 'exists:payment_transactions,id'],
            'transaction_reference' => ['nullable', 'string', 'max:64'],
            'operator' => ['required', 'string', 'in:mtn,orange,wave,moov,airtel'],
            'operator_reference' => ['required', 'string', 'min:3', 'max:128'],
            'disputed_amount' => ['required', 'numeric', 'min:50'],
            'claimant_phone' => ['required', 'string', 'regex:/^[0-9+\s]{6,20}$/'],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'currency' => ['nullable', 'string', 'size:3'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'operator.required' => 'L\'opérateur Mobile Money est obligatoire.',
            'operator.in' => 'Opérateur non pris en charge.',
            'operator_reference.required' => 'La référence de transaction communiquée par l\'opérateur (SMS) est obligatoire.',
            'disputed_amount.required' => 'Le montant prélevé en double est obligatoire.',
            'disputed_amount.min' => 'Le montant doit être supérieur à 50.',
            'claimant_phone.required' => 'Le numéro de téléphone débité est obligatoire.',
            'reason.required' => 'Veuillez décrire le problème constaté.',
        ];
    }
}
