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
            'decision' => ['required', 'string', 'in:content_removed,sound_muted,dismissed'],
            'decision_notes' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
