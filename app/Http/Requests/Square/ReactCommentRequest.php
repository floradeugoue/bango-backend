<?php

namespace App\Http\Requests\Square;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ReactCommentRequest extends FormRequest
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
            'type' => ['required', 'string', Rule::in(['like', 'dislike'])],
        ];
    }

    public function type(): string
    {
        return (string) $this->input('type');
    }
}
