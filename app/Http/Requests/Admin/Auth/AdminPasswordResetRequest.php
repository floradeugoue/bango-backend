<?php

namespace App\Http\Requests\Admin\Auth;

use App\Services\Admin\AdminPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class AdminPasswordResetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['nullable', 'string', 'max:64'],
            'password' => AdminPasswordPolicy::validationRules($this->has('password_confirmation')),
            'code' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return AdminPasswordPolicy::validationMessages();
    }
}
