<?php

namespace App\Http\Controllers\Otp;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\Otp;
use App\Models\User;
use Carbon\Carbon;
use App\Enums\OtpFailure;
use App\Enums\OtpChannel;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use App\Services\Otp\OtpEmailService;
use App\Services\Sms\ZomloaSmsService;
use Exception;
use Illuminate\Support\Facades\Log;
use App\Services\Otp\OtpService;

/**
 * @group Auth
 *
 * API de validation par OTP (One-Time Password).
 */
class OtpController extends Controller
{
    /**
     * Request OTP
     *
     * Demande l'envoi d'un code OTP par SMS ou Email.
     *
     * @bodyParam channel string required Le canal d'envoi. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     *
     * @response 200 {
     *   "success": true
     * }
     * @response 422 {
     *   "message": "The given data was invalid.",
     *   "errors": { ... }
     * }
     * @response 409 {
     *   "kind": "number-taken",
     *   "handle": "pseudo",
     *   "createdAt": "2026-10-07T19:00:00Z"
     * }
     * @response 429 {
     *   "kind": "locked",
     *   "minutes": 15,
     *   "until": "2026-10-07T20:45:00Z"
     * }
     */
    public function requestOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $this->validateOtpRequest($request);
        
        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;

        // Check if identifier already taken by another user
        $field = $channel === OtpChannel::Sms ? 'phone' : 'email';
        $existing = User::where($field, $identifier)->first();
        if ($existing && $request->user() && $existing->id !== $request->user()->id) {
            return response()->json([
                'kind' => OtpFailure::NumberTaken->value, // Or EmailTaken, we reuse NumberTaken for now
                'handle' => $existing->handle,
                'createdAt' => $existing->created_at?->toIso8601String()
            ], 409);
        }

        $result = $otpService->sendOtp($identifier, $channel, 'default');

        if (is_array($result)) {
            return response()->json($result['data'], $result['status']);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Verify OTP
     *
     * Valide un code OTP pour un canal spécifique.
     *
     * @bodyParam channel string required Le canal. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     * @bodyParam code string required Le code à 6 chiffres. Exemple: 123456
     *
     * @response 200 {
     *   "success": true
     * }
     */
    public function verifyOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $this->validateOtpRequest($request, true);

        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;

        $result = $otpService->verifyOtp($identifier, $request->code, $channel, 'default');

        if (is_array($result)) {
            return response()->json($result['data'], $result['status']);
        }

        if ($request->user()) {
            if ($channel === OtpChannel::Sms) {
                $request->user()->update([
                    'phone' => $identifier,
                    'phone_verified_at' => now()
                ]);
            } else if ($channel === OtpChannel::Email) {
                $request->user()->update([
                    'email' => $identifier,
                    'email_verified_at' => now()
                ]);
            }
        }

        return response()->json(['success' => true]);
    }

    /**
     * Resend OTP
     *
     * Renvoie un nouveau code OTP.
     *
     * @bodyParam channel string required Le canal. Exemple: sms, email
     * @bodyParam identifier string required L'identifiant (numéro de téléphone ou email).
     *
     * @response 200 {
     *   "success": true,
     *   "resendAfterSeconds": 60
     * }
     */
    public function resendOtp(Request $request, OtpService $otpService): JsonResponse
    {
        $this->validateOtpRequest($request);

        $channel = OtpChannel::from($request->channel);
        $identifier = $request->identifier;
        
        $result = $otpService->sendOtp($identifier, $channel, 'default');

        if (is_array($result)) {
            return response()->json($result['data'], $result['status']);
        }

        return response()->json([
            'success' => true,
            'resendAfterSeconds' => 60
        ]);
    }

    private function validateOtpRequest(Request $request, bool $withCode = false): void
    {
        $rules = [
            'channel' => ['required', Rule::enum(OtpChannel::class)],
            'identifier' => ['required', 'string']
        ];

        if ($request->channel === OtpChannel::Email->value) {
            $rules['identifier'][] = 'email';
        }

        if ($withCode) {
            $rules['code'] = ['required', 'string', 'size:6'];
        }

        $request->validate($rules);
    }
}
