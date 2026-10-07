<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function completeStep(Request $request): JsonResponse
    {
        $request->validate([
            'step' => 'required|string|in:credentials,identity,phone,profile,interests,housing'
        ]);

        $user = $request->user();
        $progress = $user->onboarding_progress ?? ['completed' => [], 'updatedAt' => null];

        if (!in_array($request->step, $progress['completed'])) {
            $progress['completed'][] = $request->step;
            $progress['updatedAt'] = now()->toIso8601String();
            
            $user->update([
                'onboarding_progress' => $progress
            ]);
        }

        return response()->json($progress);
    }
}
