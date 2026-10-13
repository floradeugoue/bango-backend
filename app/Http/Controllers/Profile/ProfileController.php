<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function show(Request $request): UserResource
    {
        $user = $request->user()->load('interests');

        return new UserResource($user);
    }

    public function updateIdentity(Request $request): JsonResponse
    {
        $request->validate([
            'handle' => ['required', 'string', 'min:3', 'max:20', 'regex:/^[a-z0-9.]+$/', 'unique:users,handle,'.$request->user()->id],
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
            'birthDate' => ['required', 'date', 'before_or_equal:'.Carbon::now()->subYears(18)->format('Y-m-d')],
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
