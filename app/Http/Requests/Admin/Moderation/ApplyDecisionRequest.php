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
            'action' => ['required', 'string', 'in:keep_online,hide,remove,warn_author,suspend_author,restrict_author,restriction,request_correction'],
            'justification' => ['required', 'string', 'min:3', 'max:1000'],
            'correction_delay_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'refund_credits' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
