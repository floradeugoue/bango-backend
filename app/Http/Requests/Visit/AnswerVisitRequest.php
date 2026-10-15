<?php

namespace App\Http\Requests\Visit;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AnswerVisitRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:accept,propose,decline'],
            'proposal_slot_id' => ['required_if:action,propose', 'nullable', 'string', 'uuid', 'exists:visit_slots,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
