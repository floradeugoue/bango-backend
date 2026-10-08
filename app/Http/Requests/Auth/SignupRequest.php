<?php

namespace App\Http\Requests\Auth;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\Password;

class SignupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator)
    {
        $errors = $validator->errors();

        if ($errors->has('email')) {
            $existingUser = User::where('email', $this->email)->first();
            if ($existingUser) {
                throw new HttpResponseException(response()->json([
                    'kind' => 'email-taken',
                    'existingHandle' => $existingUser->handle,
                    'lastSeenAt' => $existingUser->updated_at?->toIso8601String(),
                    'displayName' => $existingUser->display_name,
                ], 409));
            }
        }

        if ($errors->has('password')) {
            $failedRules = [];
            // Ideally we validate password strength manually to return exact failed rules
            $pwd = $this->password ?? '';
            if (strlen($pwd) < 8) $failedRules[] = 'min-length';
            if (!preg_match('/\d/', $pwd)) $failedRules[] = 'needs-digit';
            // Simple sequence check
            if (preg_match('/1234|abcd|qwerty/i', $pwd)) $failedRules[] = 'no-sequence';

            if (!empty($failedRules)) {
                throw new HttpResponseException(response()->json([
                    'kind' => 'weak-password',
                    'failed' => $failedRules
                ], 422));
            }
        }

        // Generic fallback
        throw new HttpResponseException(response()->json([
            'message' => 'Invalid data',
            'errors' => $errors->messages()
        ], 422));
    }
}
