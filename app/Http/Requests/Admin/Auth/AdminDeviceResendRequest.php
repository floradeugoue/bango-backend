<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminDeviceResendRequest extends FormRequest
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
        ];
    }
}
