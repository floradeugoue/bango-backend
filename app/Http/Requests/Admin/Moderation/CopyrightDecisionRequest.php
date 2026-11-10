<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class CopyrightDecisionRequest extends FormRequest
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
            'decision' => ['required', 'string', 'in:content_removed,sound_muted,dismissed,request_info'],
            'decision_notes' => ['required_without:message', 'nullable', 'string', 'min:3', 'max:1000'],
            'message' => ['required_without:decision_notes', 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }
}
