<?php

namespace App\Http\Requests\Square;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateCaptionRequest extends FormRequest
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
            'caption' => ['nullable', 'string', 'max:2200'],
            'body' => ['nullable', 'string', 'max:2200'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function ($validator) {
            $caption = $this->input('caption') ?? $this->input('body');
            if ($caption === null) {
                $validator->errors()->add('caption', 'Le champ légende (caption ou body) est obligatoire.');
            }
        });
    }

    public function caption(): string
    {
        return (string) ($this->input('caption') ?? $this->input('body') ?? '');
    }
}
