<?php

namespace App\Http\Requests\Square;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePostCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2200'],
        ];
    }

    public function body(): string
    {
        return trim((string) $this->input('body'));
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (trim((string) $this->input('body')) === '') {
                $validator->errors()->add('body', 'Le commentaire ne peut pas être vide.');
            }
        });
    }
}
