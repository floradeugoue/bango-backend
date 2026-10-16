<?php

namespace App\Http\Requests\Page;

use Illuminate\Foundation\Http\FormRequest;

class AdminDecideCertificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null && $this->user()->isAdmin();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:approve,reject,supplement_requested'],
            'reason' => ['required_if:decision,reject,supplement_requested', 'nullable', 'string', 'max:2000'],
        ];
    }
}
