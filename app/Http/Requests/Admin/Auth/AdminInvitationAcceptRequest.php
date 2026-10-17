<?php

namespace App\Http\Requests\Admin\Auth;

use App\Services\Admin\AdminPasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class AdminInvitationAcceptRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'password' => AdminPasswordPolicy::validationRules(true),
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
