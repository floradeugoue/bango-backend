<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfileResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Admin\AutomatedTextFilterService;
use App\Services\Boost\CampaignAttributionService;
use App\Services\Geo\NeighbourhoodVerificationService;
use App\Services\Profile\NeighbourhoodConfirmationInvitationService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    /**
     * Display the authenticated user's profile.
     */
    public function show(Request $request): UserResource
    {
        $user = $request->user()->load('interests');

        return new UserResource($user);
    }

    /**
     * Display a public profile by user ID or handle (PR-07 / PR-08 / PR-09).
     */
    public function showPublic(Request $request, string $identifier): JsonResponse|PublicProfileResource
    {
        $cleanHandle = ltrim(trim($identifier), '@');

        /** @var User|null $user */
        $user = User::withTrashed()
            ->where(function ($query) use ($cleanHandle, $identifier) {
                if (is_numeric($identifier)) {
                    $query->where('id', (int) $identifier);
                } else {
                    $query->where('handle', strtolower($cleanHandle));
                }
            })
            ->first();

        if (! $user || $user->trashed()) {
            return response()->json([
                'kind' => 'not-found',
                'message' => 'Ce compte n\'existe plus.',
            ], 404);
        }

        if ($user->status === 'blocked') {
            return response()->json([
                'kind' => 'blocked',
                'message' => 'Ce compte est bloqué.',
            ], 403);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'kind' => 'suspended',
                'message' => 'Ce compte est suspendu.',
            ], 403);
        }

        // Attribution publicitaire automatique (Boost) si visite post-impression < 24h
        if ($request->user()) {
            app(CampaignAttributionService::class)->attributeProfileVisit($request->user(), $user);
        }

        return new PublicProfileResource($user);
    }

    /**
     * Update full or partial profile (PUT /profile or PUT /profile/).
     */
    public function update(
        Request $request,
        NeighbourhoodVerificationService $neighbourhoodVerification,
        NeighbourhoodConfirmationInvitationService $invitationService
    ): JsonResponse {
        $user = $request->user();

        $validated = $request->validate([
            'handle' => ['sometimes', 'required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9.]+$/', 'unique:users,handle,'.$user->id],
            'displayName' => ['nullable', 'string', 'max:50'],
            'display_name' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:120'],
            'locale' => ['sometimes', 'required', 'string', 'in:fr,en'],
            'uses_agenda' => ['sometimes', 'boolean'],
            'countryCode' => ['nullable', 'string', 'size:2'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'countryId' => ['nullable', 'exists:countries,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'cityId' => ['nullable', 'exists:cities,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhoodId' => ['nullable', 'integer'],
            'neighbourhood_id' => ['nullable', 'integer'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
            'birthday_visibility' => ['sometimes', 'in:none,mutuals'],
            'birthdayVisibility' => ['sometimes', 'in:none,mutuals'],
        ]);

        $updates = [];

        if (isset($validated['handle'])) {
            $updates['handle'] = strtolower($validated['handle']);
        }

        if (array_key_exists('displayName', $validated)) {
            $updates['display_name'] = $validated['displayName'];
        } elseif (array_key_exists('display_name', $validated)) {
            $updates['display_name'] = $validated['display_name'];
        } elseif (array_key_exists('name', $validated)) {
            $updates['display_name'] = $validated['name'];
        }

        if (array_key_exists('bio', $validated)) {
            $updates['bio'] = $validated['bio'];
        }

        if (array_key_exists('website', $validated)) {
            $updates['website'] = $validated['website'];
        }

        if (isset($validated['locale'])) {
            $updates['locale'] = $validated['locale'];
        }

        if (array_key_exists('uses_agenda', $validated)) {
            $updates['uses_agenda'] = (bool) $validated['uses_agenda'];
        }

        if (array_key_exists('birthday_visibility', $validated)) {
            $updates['birthday_visibility'] = $validated['birthday_visibility'];
        } elseif (array_key_exists('birthdayVisibility', $validated)) {
            $updates['birthday_visibility'] = $validated['birthdayVisibility'];
        }

        $locationResult = $neighbourhoodVerification->resolveProfileLocationUpdates($user, $validated);
        $updates = array_merge($updates, $locationResult['updates']);

        if (! empty($updates)) {
            $user->update($updates);
            $fresh = $user->fresh();
            $textToScan = trim(($fresh->display_name ?? '').' '.($fresh->bio ?? ''));
            if ($textToScan !== '') {
                app(AutomatedTextFilterService::class)->scanText($textToScan, 'user', $user->id, $user);
            }
        }

        if ($locationResult['neighbourhood_just_verified']) {
            $invitationService->markInvitationsRead($user);
        }

        return response()->json([
            'success' => true,
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Update identity: handle, displayName, and optionally bio.
     */
    public function updateIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'handle' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9.]+$/', 'unique:users,handle,'.$request->user()->id],
            'displayName' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:120'],
            'locale' => ['nullable', 'string', 'in:fr,en'],
        ]);

        $updates = [
            'handle' => strtolower($request->handle),
            'display_name' => $request->displayName,
        ];

        if ($request->has('bio')) {
            $updates['bio'] = $request->bio;
        }

        if ($request->has('website')) {
            $updates['website'] = $request->website;
        }

        if ($request->has('locale')) {
            $updates['locale'] = $request->locale;
        }

        $request->user()->update($updates);
        $fresh = $request->user()->fresh();
        $textToScan = trim(($fresh->display_name ?? '').' '.($fresh->bio ?? ''));
        if ($textToScan !== '') {
            app(AutomatedTextFilterService::class)->scanText($textToScan, 'user', $fresh->id, $fresh);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Update birthdate (must be 18+).
     * LG-09 : Si l'utilisateur est mineur (< 18 ans), le compte et ses jetons sont immédiatement effacés.
     */
    public function updateBirthdate(Request $request): JsonResponse
    {
        $rawBirthDate = $request->input('birthDate') ?? $request->input('birth_date');

        if (! $rawBirthDate) {
            return response()->json([
                'message' => 'The birthDate field is required.',
                'errors' => [
                    'birthDate' => ['Le champ date de naissance est requis.'],
                ],
            ], 422);
        }

        try {
            $birthDate = Carbon::parse($rawBirthDate);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'The birthDate must be a valid date.',
                'errors' => [
                    'birthDate' => ['La date de naissance est invalide.'],
                ],
            ], 422);
        }

        if ($birthDate->isAfter(Carbon::now()->subYears(18))) {
            $user = $request->user();
            if ($user) {
                $user->tokens()->delete();
                $user->forceDelete();
            }

            return response()->json([
                'message' => 'L\'inscription est réservée aux personnes majeures (18 ans et plus). Vos données ont été effacées.',
                'errors' => [
                    'birthDate' => ['Vous devez avoir au moins 18 ans pour utiliser BANGO.'],
                ],
            ], 422);
        }

        $request->user()->update([
            'birth_date' => $birthDate->toDateString(),
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * Signale un utilisateur comme mineur et efface immédiatement et définitivement son compte et ses données.
     * Sans mot de passe ni délai de 30 jours (LG-09).
     */
    public function reportUnderage(Request $request): JsonResponse
    {
        $user = $request->user();
        if ($user) {
            $user->tokens()->delete();
            $user->forceDelete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Le compte et les données associées ont été immédiatement et définitivement effacés.',
        ], 200);
    }

    /**
     * Update gender and public visibility.
     */
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

    /**
     * Update location (country, city, neighbourhood) with geolocation proof for neighbourhood.
     */
    public function updateLocation(
        Request $request,
        NeighbourhoodVerificationService $neighbourhoodVerification,
        NeighbourhoodConfirmationInvitationService $invitationService
    ): JsonResponse {
        $user = $request->user();

        $validated = $request->validate([
            'countryCode' => ['nullable', 'string', 'size:2'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'countryId' => ['nullable', 'exists:countries,id'],
            'country_id' => ['nullable', 'exists:countries,id'],
            'cityId' => ['nullable', 'exists:cities,id'],
            'city_id' => ['nullable', 'exists:cities,id'],
            'neighbourhoodId' => ['nullable', 'integer'],
            'neighbourhood_id' => ['nullable', 'integer'],
            'city' => ['nullable', 'string', 'max:100'],
            'neighbourhood' => ['nullable', 'string', 'max:100'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $locationResult = $neighbourhoodVerification->resolveProfileLocationUpdates($user, $validated);

        if (! empty($locationResult['updates'])) {
            $user->update($locationResult['updates']);
        }

        if ($locationResult['neighbourhood_just_verified']) {
            $invitationService->markInvitationsRead($user);
        }

        return response()->json([
            'success' => true,
            'user' => new UserResource($user->fresh()),
        ]);
    }

    /**
     * Update biography.
     */
    public function updateBio(Request $request): JsonResponse
    {
        $request->validate([
            'bio' => ['nullable', 'string', 'max:500'],
        ]);

        $request->user()->update([
            'bio' => $request->bio,
        ]);

        if (! empty($request->bio)) {
            app(AutomatedTextFilterService::class)->scanText($request->bio, 'user', $request->user()->id, $request->user());
        }

        return response()->json(['success' => true]);
    }

    /**
     * Update user interests (min 3 items).
     */
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
