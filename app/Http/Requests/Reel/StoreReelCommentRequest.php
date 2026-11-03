<?php

namespace App\Http\Requests\Reel;

use App\Models\Reel\Reel;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreReelCommentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $reel = $this->route('reel');
        if ($reel instanceof Reel) {
            $author = $reel->relationLoaded('user') ? $reel->user : User::find($reel->user_id);
            if ($author && ! $author->canBeCommentedBy($this->user())) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:2000'],
            'parent_id' => ['nullable', 'integer', 'exists:reel_comments,id'],
        ];
    }
}
