<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class SocialAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider' => ['required', 'string', 'in:google,apple'],
            'id_token' => ['required', 'string'],
            'nonce' => ['nullable', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'device_name' => ['nullable', 'string', 'max:100'],
            'persistent' => ['nullable', 'boolean'],
            'action' => ['nullable', 'string', 'in:signin,signup,link'],
        ];
    }

    public function messages(): array
    {
        return [
            'provider.required' => 'Le fournisseur d\'authentification est obligatoire.',
            'provider.in' => 'Le fournisseur doit être soit google, soit apple.',
            'id_token.required' => 'Le jeton d\'identité (id_token) est obligatoire.',
        ];
    }
}
