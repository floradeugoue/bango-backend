<?php

namespace App\Http\Requests\Reel;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ReelShareRequest extends FormRequest
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
            'platform' => ['nullable', 'string', 'max:50'],
            'client_mutation_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
