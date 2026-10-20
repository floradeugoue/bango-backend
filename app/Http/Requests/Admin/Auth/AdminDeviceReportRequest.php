<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminDeviceReportRequest extends FormRequest
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
        if (! $this->has('reason')) {
            $this->merge(['reason' => 'Appareil inconnu ou non reconnu']);
        }
    }

    /**
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'challenge_token' => ['nullable', 'string', 'max:64'],
            'challengeId' => ['nullable', 'string', 'max:64'],
            'device_hash' => ['nullable', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
