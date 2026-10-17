<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class Admin2FaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'size:64'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'challenge_token.required' => 'Le jeton de challenge 2FA est requis.',
            'challenge_token.size' => 'Le jeton de challenge 2FA est invalide.',
            'code.required' => 'Le code de vérification est requis.',
            'code.regex' => 'Le code doit être composé de 6 chiffres.',
        ];
    }
}
