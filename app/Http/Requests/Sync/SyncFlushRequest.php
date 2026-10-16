<?php

namespace App\Http\Requests\Sync;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class SyncFlushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'actions' => ['required', 'array', 'min:1', 'max:50'],
            'actions.*.action_id' => ['required', 'string', 'max:255'],
            'actions.*.type' => ['required', 'string', 'in:like_post,unlike_post,like_reel,save_reel,view_reel,bookmark_listing,unbookmark_listing,follow_user,unfollow_user'],
            'actions.*.target_id' => ['required', 'string', 'max:255'],
            'actions.*.payload' => ['nullable', 'array'],
            'actions.*.occurred_at' => ['nullable', 'date'],
        ];
    }
}
