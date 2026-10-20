<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class Admin2FaRecoveryRequest extends FormRequest
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
        if ($this->has('key') && ! $this->has('recovery_code')) {
            $this->merge(['recovery_code' => $this->input('key')]);
        }
        if ($this->has('recovery_code') && ! $this->has('key')) {
            $this->merge(['key' => $this->input('recovery_code')]);
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
            'recovery_code' => ['required_without:key', 'nullable', 'string'],
            'key' => ['required_without:recovery_code', 'nullable', 'string'],
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
            'recovery_code.required_without' => 'Le code de secours est requis.',
            'key.required_without' => 'Le code de secours est requis.',
        ];
    }
}
