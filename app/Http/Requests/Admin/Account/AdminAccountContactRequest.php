<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccountContactRequest extends FormRequest
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
            'channel' => ['required', 'string', 'in:in_app,sms,ticket'],
            'subject' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
