<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\User;
use App\Enums\HandleVerdict;
use Illuminate\Support\Str;

class HandleCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $handle = $request->input('handle');
        
        if (strlen($handle) < 3) {
            return response()->json(['state' => HandleVerdict::TooShort->value]);
        }
        if (strlen($handle) > 20) {
            return response()->json(['state' => HandleVerdict::TooLong->value]);
        }
        if (!preg_match('/^[a-z0-9.]+$/', $handle)) {
            return response()->json(['state' => HandleVerdict::BadShape->value]);
        }
        if (in_array($handle, ['admin', 'bango', 'support', 'root'])) {
            return response()->json(['state' => HandleVerdict::Reserved->value]);
        }

        $existing = User::where('handle', $handle)->first();
        if ($existing) {
            $suggestions = [
                $handle . rand(10, 99),
                $handle . Str::lower($request->input('city') ?? rand(100, 999)),
                $handle . '_' . rand(10, 99)
            ];
            return response()->json([
                'state' => HandleVerdict::Taken->value,
                'since' => $existing->created_at ? $existing->created_at->format('Y') : null,
                'suggestions' => $suggestions
            ]);
        }

        return response()->json(['state' => HandleVerdict::Free->value]);
    }
}
