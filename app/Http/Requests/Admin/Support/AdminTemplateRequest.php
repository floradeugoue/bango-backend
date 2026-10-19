<?php

namespace App\Http\Requests\Admin\Support;

use Illuminate\Foundation\Http\FormRequest;

class AdminTemplateRequest extends FormRequest
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
        $id = $this->route('id') ?? $this->route('template');

        return [
            'title' => ['required', 'string', 'max:160'],
            'code' => ['nullable', 'string', 'max:64', 'unique:support_response_templates,code,'.($id ? (int) $id : 'NULL').',id'],
            'category' => ['required', 'string', 'max:64'],
            'content' => ['required', 'string'],
            'locale' => ['nullable', 'string', 'max:8'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
