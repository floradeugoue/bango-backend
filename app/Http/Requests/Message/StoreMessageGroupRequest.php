<?php

namespace App\Http\Requests\Message;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageGroupRequest extends FormRequest
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
            'title' => [
                'required',
                'string',
                'min:2',
                'max:100',
            ],
            'members' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'members.*' => [
                'integer',
                'exists:users,id',
            ],
            'avatar_url' => [
                'nullable',
                'url',
                'max:2048',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'title.required' => 'Le nom du groupe est obligatoire.',
            'title.min' => 'Le nom du groupe doit contenir au moins 2 caractères.',
            'title.max' => 'Le nom du groupe ne peut pas dépasser 100 caractères.',
            'members.required' => 'Veuillez spécifier au moins un membre pour créer le groupe.',
            'members.min' => 'Un groupe doit comporter au moins un autre membre.',
            'members.max' => 'Un groupe ne peut pas dépasser 100 membres.',
            'members.*.exists' => 'L\'un des membres spécifiés n\'existe pas.',
        ];
    }
}
