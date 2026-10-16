<?php

namespace App\Http\Requests\Support;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreModerationAppealRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sanction_type' => ['required', 'string', 'in:account_suspension,page_suspension,content_removal,warning'],
            'sanction_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'min:10', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
