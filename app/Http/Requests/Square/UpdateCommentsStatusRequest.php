<?php

namespace App\Http\Requests\Square;

use App\Enums\Square\PostCommentsBy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCommentsStatusRequest extends FormRequest
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
            'comments_closed' => ['nullable', 'boolean'],
            'commentsClosed' => ['nullable', 'boolean'],
            'comments_by' => ['nullable', 'string', Rule::in(PostCommentsBy::values())],
            'commentsBy' => ['nullable', 'string', Rule::in(PostCommentsBy::values())],
        ];
    }

    public function commentsClosed(): ?bool
    {
        if ($this->has('comments_closed')) {
            return $this->boolean('comments_closed');
        }
        if ($this->has('commentsClosed')) {
            return $this->boolean('commentsClosed');
        }

        return null;
    }

    public function commentsBy(): ?string
    {
        return $this->input('comments_by') ?? $this->input('commentsBy');
    }
}
