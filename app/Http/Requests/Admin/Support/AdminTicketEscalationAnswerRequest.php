<?php

namespace App\Http\Requests\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

class AdminTicketEscalationAnswerRequest extends FormRequest
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
            'escalation_id' => ['required', 'integer', 'exists:support_ticket_escalations,id'],
            'response' => ['required', 'string', 'min:3', 'max:5000'],
        ];
    }
}
