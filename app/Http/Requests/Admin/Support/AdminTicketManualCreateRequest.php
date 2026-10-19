<?php

namespace App\Http\Requests\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

class AdminTicketManualCreateRequest extends FormRequest
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
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'category' => ['required', 'string', 'max:64'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'channel' => ['required', 'string', 'in:phone,email,external,in_app'],
            'message' => ['required', 'string', 'min:5', 'max:10000'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
        ];
    }
}
