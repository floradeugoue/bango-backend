<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class ApplyDecisionRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:keep_online,hide,remove,warn_author,suspend_author'],
            'justification' => ['required', 'string', 'min:3', 'max:1000'],
            'refund_credits' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
