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
            'moderation_decision_id' => ['nullable', 'integer', 'exists:moderation_decisions,id'],
            'sanction_type' => ['required_without:moderation_decision_id', 'nullable', 'string', 'in:account_suspension,page_suspension,content_removal,warning'],
            'sanction_reference' => ['nullable', 'string', 'max:255'],
            'reason' => ['required', 'string', 'max:255'],
            'explanation' => ['required', 'string', 'min:10', 'max:3000'],
            'contact_email' => ['nullable', 'email', 'max:255'],
        ];
    }
}
