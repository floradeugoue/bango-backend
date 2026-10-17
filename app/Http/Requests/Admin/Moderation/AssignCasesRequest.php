<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class AssignCasesRequest extends FormRequest
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
            'case_refs' => ['required', 'array', 'min:1', 'max:50'],
            'case_refs.*' => ['required', 'string'],
            'operator_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
