<?php

namespace App\Http\Requests\Landlord;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InviteAgentRequest extends FormRequest
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
            'listing_id' => ['required', 'uuid', 'exists:listings,id'],
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'capabilities' => ['nullable', 'array'],
            'capabilities.*' => ['string', 'in:visits,reply,edit'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'listing_id.required' => 'L\'identifiant de l\'annonce immobilière est obligatoire.',
            'listing_id.exists' => 'L\'annonce immobilière indiquée est introuvable.',
            'agent_id.required' => 'L\'agent immobilier à inviter est obligatoire.',
            'agent_id.exists' => 'L\'agent immobilier indiqué est introuvable.',
            'capabilities.*.in' => 'Capacité invalide (seules les capacités visits, reply et edit sont acceptées).',
        ];
    }
}
