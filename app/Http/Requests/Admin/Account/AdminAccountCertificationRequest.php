<?php

namespace App\Http\Requests\Admin\Account;

use Illuminate\Foundation\Http\FormRequest;

class AdminAccountCertificationRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:account,page'],
            'page_id' => ['required_if:type,page', 'nullable', 'integer', 'exists:pages,id'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
