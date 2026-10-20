<?php

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;
use Database\Seeders\GeoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('point 49: geo seeder populates countries including ghana and nigeria with cities and neighbourhoods', function () {
    $this->seed(GeoSeeder::class);

    // Countries (CM, CI, SN, GA, FR, GH, NG)
    $countries = Country::pluck('code')->all();
    expect($countries)->toContain('CM', 'CI', 'SN', 'GA', 'FR', 'GH', 'NG');

    // Ghana & Nigeria present
    $ghana = Country::where('code', 'GH')->first();
    expect($ghana)->not->toBeNull();
    expect($ghana->phone_code)->toBe('+233');

    $nigeria = Country::where('code', 'NG')->first();
    expect($nigeria)->not->toBeNull();
    expect($nigeria->phone_code)->toBe('+234');

    // Cities
    $citySlugs = City::pluck('slug')->all();
    expect($citySlugs)->toContain('douala', 'yaounde', 'abidjan', 'dakar', 'libreville', 'paris', 'accra', 'kumasi', 'lagos', 'abuja');

    // Neighbourhoods
    $neighbourhoodsCount = Neighbourhood::count();
    expect($neighbourhoodsCount)->toBeGreaterThanOrEqual(40);

    // Accra and Lagos neighbourhoods
    $accra = City::where('slug', 'accra')->first();
    expect($accra->neighbourhoods()->count())->toBeGreaterThanOrEqual(4);

    $lagos = City::where('slug', 'lagos')->first();
    expect($lagos->neighbourhoods()->count())->toBeGreaterThanOrEqual(4);

    // Operators
    $opCodes = Operator::pluck('code')->all();
    expect($opCodes)->toContain('mtn_gh', 'telecel_gh', 'mtn_ng', 'airtel_ng');
});
