<?php

namespace App\Http\Requests\Admin\Moderation;

use Illuminate\Foundation\Http\FormRequest;

class BulkDecisionRequest extends FormRequest
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
            'action' => ['required', 'string', 'in:keep_online,hide,remove,warn_author,suspend_author'],
            'justification' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
