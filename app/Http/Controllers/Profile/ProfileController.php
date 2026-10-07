<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ProfileController extends Controller
{
    public function show(Request $request): \App\Http\Resources\UserResource
    {
        $user = $request->user()->load('interests');
        return new \App\Http\Resources\UserResource($user);
    }

    public function updateIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'handle' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9.]+$/', 'unique:users,handle,' . $request->user()->id],
            'displayName' => ['nullable', 'string', 'max:50'],
        ]);

        $request->user()->update([
            'handle' => $request->handle,
            'display_name' => $request->displayName,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateBirthdate(Request $request): JsonResponse
    {
        $request->validate([
            'birthDate' => ['required', 'date', 'before_or_equal:' . Carbon::now()->subYears(18)->format('Y-m-d')],
        ]);

        $request->user()->update([
            'birth_date' => $request->birthDate,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateGender(Request $request): JsonResponse
    {
        $request->validate([
            'gender' => ['required', 'in:woman,man,other,undisclosed'],
            'hidden' => ['required', 'boolean'],
        ]);

        $request->user()->update([
            'gender' => $request->gender,
            'gender_hidden' => $request->hidden,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'countryCode' => ['required', 'string', 'size:2'],
        ]);

        $request->user()->update([
            'country_code' => $request->countryCode,
        ]);

        return response()->json(['success' => true]);
    }

    public function updateInterests(Request $request): JsonResponse
    {
        $request->validate([
            'interests' => ['required', 'array', 'min:3'],
            'interests.*' => ['string', 'exists:interests,id'],
        ]);

        $request->user()->interests()->sync($request->interests);

        return response()->json(['success' => true]);
    }
}
