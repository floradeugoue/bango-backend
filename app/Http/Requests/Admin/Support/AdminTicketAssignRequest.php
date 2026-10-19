<?php

namespace App\Http\Requests\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

class AdminTicketAssignRequest extends FormRequest
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
            'ticket_ids' => ['nullable', 'array', 'max:50'],
            'ticket_ids.*' => ['integer', 'exists:support_tickets,id'],
            'ticket_id' => ['nullable', 'integer', 'exists:support_tickets,id'],
            'agent_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
