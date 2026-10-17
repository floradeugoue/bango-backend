<?php

namespace App\Http\Requests\Admin\Auth;

use Illuminate\Foundation\Http\FormRequest;

class AdminDeviceReportRequest extends FormRequest
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
            'challenge_token' => ['nullable', 'string', 'max:64'],
            'device_hash' => ['nullable', 'string', 'max:64'],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
