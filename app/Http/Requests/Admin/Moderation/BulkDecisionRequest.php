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
            'action' => [
                'required',
                'string',
                'in:keep_online,hide,remove,warn_author,suspend_author,request_correction,warn_page,suspend_page,unsuspend_page,lift_suspension,unsuspend,warn,suspend,lift',
            ],
            'justification' => ['required', 'string', 'min:3', 'max:1000'],
            'correction_delay_hours' => ['nullable', 'integer', 'min:1', 'max:720'],
        ];
    }
}
