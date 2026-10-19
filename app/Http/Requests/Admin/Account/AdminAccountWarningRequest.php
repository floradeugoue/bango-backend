<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccountWarningRequest extends FormRequest
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
            'level' => ['required', 'integer', 'in:1,2,3'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
            'apply_restriction' => ['nullable', 'boolean'],
        ];
    }
}
