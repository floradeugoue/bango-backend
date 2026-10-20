<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class Admin2FaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('challengeId') && ! $this->has('challenge_token')) {
            $this->merge(['challenge_token' => $this->input('challengeId')]);
        }
        if ($this->has('challenge_token') && ! $this->has('challengeId')) {
            $this->merge(['challengeId' => $this->input('challenge_token')]);
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['required_without:challengeId', 'nullable', 'string', 'size:64'],
            'challengeId' => ['required_without:challenge_token', 'nullable', 'string', 'size:64'],
            'code' => ['required', 'string'],
            'email' => ['nullable', 'string', 'email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'challenge_token.required_without' => 'Le jeton de challenge 2FA est requis.',
            'challengeId.required_without' => 'Le jeton de challenge 2FA est requis.',
            'challenge_token.size' => 'Le jeton de challenge 2FA est invalide.',
            'challengeId.size' => 'Le jeton de challenge 2FA est invalide.',
            'code.required' => 'Le code de vérification est requis.',
        ];
    }
}
