<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageThreadRequest extends FormRequest
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
            'peer_id' => [
                'required_without:page_id',
                'nullable',
                'integer',
                'exists:users,id',
                'different:'.$this->user()?->id,
            ],
            'page_id' => [
                'required_without:peer_id',
                'nullable',
                'integer',
                'exists:pages,id',
            ],
            'listing_id' => [
                'nullable',
                'uuid',
                'exists:listings,id',
            ],
            'metadata' => [
                'nullable',
                'array',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'peer_id.required_without' => 'Un destinataire (peer_id ou page_id) est obligatoire pour initier une conversation.',
            'peer_id.different' => 'Vous ne pouvez pas démarrer une conversation avec vous-même.',
            'peer_id.exists' => 'L\'utilisateur sélectionné n\'existe pas.',
            'page_id.required_without' => 'Un destinataire (peer_id ou page_id) est obligatoire pour initier une conversation.',
            'page_id.exists' => 'La Page Professionnelle sélectionnée n\'existe pas.',
            'listing_id.exists' => 'L\'annonce immobilière spécifiée n\'existe pas.',
        ];
    }
}
