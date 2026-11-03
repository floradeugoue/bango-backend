<?php

namespace App\Http\Requests\Auth;

use App\Enums\Support\TicketCategory;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SignedOutSupportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'topic' => ['required', 'string', Rule::enum(TicketCategory::class)],
            'subject' => ['required', 'string', 'min:3', 'max:255'],
            'body' => ['required', 'string', 'min:5', 'max:5000'],
        ];
    }
}
