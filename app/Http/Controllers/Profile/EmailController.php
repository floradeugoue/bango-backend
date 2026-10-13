<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Otp\OtpEmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

class EmailController extends Controller
{
    public function requestChange(Request $request, OtpEmailService $emailService)
    {
        $request->validate([
            'address' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            return response()->json(['kind' => 'bad-password'], 403);
        }

        if (strtolower($request->address) === strtolower($user->email)) {
            return response()->json(['kind' => 'same'], 422);
        }

        if (User::where('email', $request->address)->exists()) {
            return response()->json(['kind' => 'taken'], 409);
        }

        $code = (string) random_int(100000, 999999);

        $user->update([
            'pending_email' => $request->address,
            'pending_email_code' => Hash::make($code),
            'pending_email_expires_at' => now()->addMinutes(15),
            'pending_email_attempts' => 4,
        ]);

        try {
            $emailService->send($request->address, $code);
        } catch (\Exception $e) {
            Log::error('Failed to send email change verification code', [
                'email' => $request->address,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'address' => $request->address,
            'expiresAt' => $user->pending_email_expires_at->toIso8601String(),
            'attemptsLeft' => 4,
        ]);
    }

    public function verifyChange(Request $request)
    {
        $request->validate(['code' => 'required|string']);
        $user = $request->user();

        if (! $user->pending_email || ! $user->pending_email_code || now()->greaterThan($user->pending_email_expires_at)) {
            return response()->json(['reason' => 'expired', 'attemptsLeft' => 0], 410);
        }

        if (! Hash::check($request->code, $user->pending_email_code)) {
            $user->decrement('pending_email_attempts');
            $attempts = $user->pending_email_attempts;

            if ($attempts <= 0) {
                $user->update(['pending_email' => null, 'pending_email_code' => null]);

                return response()->json(['reason' => 'expired', 'attemptsLeft' => 0], 410);
            }

            return response()->json(['reason' => 'wrong', 'attemptsLeft' => $attempts], 422);
        }

        if (User::where('email', $user->pending_email)->exists()) {
            $user->update(['pending_email' => null, 'pending_email_code' => null]);

            return response()->json(['kind' => 'taken'], 409);
        }

        $previous = $user->email;
        $user->update([
            'email' => $user->pending_email,
            'pending_email' => null,
            'pending_email_code' => null,
        ]);

        return response()->json(['previous' => $previous]);
    }

    public function resendCode(Request $request, OtpEmailService $emailService)
    {
        $user = $request->user();
        if (! $user->pending_email) {
            return response()->json(['reason' => 'none', 'attemptsLeft' => 0], 422);
        }

        $code = (string) random_int(100000, 999999);
        $user->update([
            'pending_email_code' => Hash::make($code),
            'pending_email_expires_at' => now()->addMinutes(15),
            'pending_email_attempts' => 4,
        ]);

        try {
            $emailService->send($user->pending_email, $code);
        } catch (\Exception $e) {
            Log::error('Failed to resend email change verification code', [
                'email' => $user->pending_email,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json([
            'address' => $user->pending_email,
            'expiresAt' => $user->pending_email_expires_at->toIso8601String(),
            'attemptsLeft' => 4,
        ]);
    }

    public function cancelChange(Request $request)
    {
        $request->user()->update([
            'pending_email' => null,
            'pending_email_code' => null,
            'pending_email_expires_at' => null,
            'pending_email_attempts' => 0,
        ]);

        return response()->json(['success' => true]);
    }
}
