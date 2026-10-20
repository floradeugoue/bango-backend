<?php

namespace App\Http\Requests\Legal;

use Illuminate\Foundation\Http\FormRequest;

class StoreCopyrightClaimRequest extends FormRequest
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
            'claimant_name' => ['required', 'string', 'max:255'],
            'claimant_email' => ['required', 'email', 'max:255'],
            'content_type' => ['required', 'string', 'in:post,reel,sound,listing,page,comment,other'],
            'content_id' => ['required', 'string', 'max:255'],
            'work_title' => ['required', 'string', 'max:255'],
            'proof_url' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'max:5000'],
        ];
    }
}
