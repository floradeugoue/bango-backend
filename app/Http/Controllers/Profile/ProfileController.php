<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\PublicProfileResource;
use App\Http\Resources\UserResource;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\User;
use App\Services\Boost\CampaignAttributionService;
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
     * Update identity: handle, displayName, and optionally bio.
     */
    public function updateIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'handle' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9.]+$/', 'unique:users,handle,'.$request->user()->id],
            'displayName' => ['nullable', 'string', 'max:50'],
            'bio' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:120'],
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

        $request->user()->update($updates);

        return response()->json(['success' => true]);
    }

    /**
     * Update birthdate (must be 18+).
     * LG-09 : Si l'utilisateur est mineur (< 18 ans), le compte et ses jetons sont immédiatement effacés.
     */
    public function updateBirthdate(Request $request): JsonResponse
    {
        $request->validate([
            'birthDate' => ['required', 'date'],
        ]);

        $birthDate = Carbon::parse($request->birthDate);
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
            'birth_date' => $request->birthDate,
        ]);

        return response()->json(['success' => true]);
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
     * Update location (country, city, neighbourhood).
     */
    public function updateLocation(Request $request): JsonResponse
    {
        $request->validate([
            'countryCode' => ['nullable', 'string', 'size:2'],
            'countryId' => ['nullable', 'exists:countries,id'],
            'cityId' => ['nullable', 'exists:cities,id'],
            'neighbourhoodId' => ['nullable', 'exists:neighbourhoods,id'],
            'city' => ['nullable', 'string'],
            'neighbourhood' => ['nullable', 'string'],
        ]);

        $updates = [];
        if ($request->filled('countryCode')) {
            $updates['country_code'] = strtoupper($request->countryCode);
            $country = Country::where('iso2', strtoupper($request->countryCode))->first();
            if ($country) {
                $updates['country_id'] = $country->id;
            }
        }
        if ($request->filled('countryId')) {
            $updates['country_id'] = $request->countryId;
            $country = Country::find($request->countryId);
            if ($country) {
                $updates['country_code'] = $country->iso2;
            }
        }
        if ($request->filled('cityId')) {
            $updates['city_id'] = $request->cityId;
            $city = City::find($request->cityId);
            if ($city) {
                $updates['city'] = $city->name;
            }
        } elseif ($request->filled('city')) {
            $updates['city'] = $request->city;
        }
        if ($request->filled('neighbourhoodId')) {
            $updates['neighbourhood_id'] = $request->neighbourhoodId;
            $n = Neighbourhood::find($request->neighbourhoodId);
            if ($n) {
                $updates['neighbourhood'] = $n->name;
            }
        } elseif ($request->filled('neighbourhood')) {
            $updates['neighbourhood'] = $request->neighbourhood;
        }

        if (! empty($updates)) {
            $request->user()->update($updates);
        }

        return response()->json(['success' => true]);
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
