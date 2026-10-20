<?php

namespace Database\Seeders;

use App\Models\Geo\City;
use App\Models\Geo\Country;
use App\Models\Geo\Currency;
use App\Models\Geo\Neighbourhood;
use App\Models\Geo\Operator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class GeoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Currencies
        $xaf = Currency::firstOrCreate(
            ['code' => 'XAF'],
            ['name' => 'Franc CFA BEAC', 'symbol' => 'FCFA']
        );

        $xof = Currency::firstOrCreate(
            ['code' => 'XOF'],
            ['name' => 'Franc CFA BCEAO', 'symbol' => 'FCFA']
        );

        $eur = Currency::firstOrCreate(
            ['code' => 'EUR'],
            ['name' => 'Euro', 'symbol' => '€']
        );

        $ghs = Currency::firstOrCreate(
            ['code' => 'GHS'],
            ['name' => 'Ghanaian Cedi', 'symbol' => 'GH₵']
        );

        $ngn = Currency::firstOrCreate(
            ['code' => 'NGN'],
            ['name' => 'Nigerian Naira', 'symbol' => '₦']
        );

        // 2. Countries
        $cameroun = Country::updateOrCreate(
            ['code' => 'CM'],
            [
                'name' => 'Cameroun',
                'name_en' => 'Cameroon',
                'iso2' => 'CM',
                'iso3' => 'CMR',
                'phone_code' => '+237',
                'currency_id' => $xaf->id,
            ]
        );

        $cotedivoire = Country::updateOrCreate(
            ['code' => 'CI'],
            [
                'name' => "Côte d'Ivoire",
                'name_en' => 'Ivory Coast',
                'iso2' => 'CI',
                'iso3' => 'CIV',
                'phone_code' => '+225',
                'currency_id' => $xof->id,
            ]
        );

        $senegal = Country::updateOrCreate(
            ['code' => 'SN'],
            [
                'name' => 'Sénégal',
                'name_en' => 'Senegal',
                'iso2' => 'SN',
                'iso3' => 'SEN',
                'phone_code' => '+221',
                'currency_id' => $xof->id,
            ]
        );

        $gabon = Country::updateOrCreate(
            ['code' => 'GA'],
            [
                'name' => 'Gabon',
                'name_en' => 'Gabon',
                'iso2' => 'GA',
                'iso3' => 'GAB',
                'phone_code' => '+241',
                'currency_id' => $xaf->id,
            ]
        );

        $france = Country::updateOrCreate(
            ['code' => 'FR'],
            [
                'name' => 'France',
                'name_en' => 'France',
                'iso2' => 'FR',
                'iso3' => 'FRA',
                'phone_code' => '+33',
                'currency_id' => $eur->id,
            ]
        );

        $ghana = Country::updateOrCreate(
            ['code' => 'GH'],
            [
                'name' => 'Ghana',
                'name_en' => 'Ghana',
                'iso2' => 'GH',
                'iso3' => 'GHA',
                'phone_code' => '+233',
                'currency_id' => $ghs->id,
            ]
        );

        $nigeria = Country::updateOrCreate(
            ['code' => 'NG'],
            [
                'name' => 'Nigéria',
                'name_en' => 'Nigeria',
                'iso2' => 'NG',
                'iso3' => 'NGA',
                'phone_code' => '+234',
                'currency_id' => $ngn->id,
            ]
        );

        // 3. Cities
        $douala = City::firstOrCreate(
            ['slug' => 'douala'],
            ['name' => 'Douala', 'country_id' => $cameroun->id]
        );

        $yaounde = City::firstOrCreate(
            ['slug' => 'yaounde'],
            ['name' => 'Yaoundé', 'country_id' => $cameroun->id]
        );

        $abidjan = City::firstOrCreate(
            ['slug' => 'abidjan'],
            ['name' => 'Abidjan', 'country_id' => $cotedivoire->id]
        );

        $dakar = City::firstOrCreate(
            ['slug' => 'dakar'],
            ['name' => 'Dakar', 'country_id' => $senegal->id]
        );

        $libreville = City::firstOrCreate(
            ['slug' => 'libreville'],
            ['name' => 'Libreville', 'country_id' => $gabon->id]
        );

        $paris = City::firstOrCreate(
            ['slug' => 'paris'],
            ['name' => 'Paris', 'country_id' => $france->id]
        );

        $accra = City::firstOrCreate(
            ['slug' => 'accra'],
            ['name' => 'Accra', 'country_id' => $ghana->id]
        );

        $kumasi = City::firstOrCreate(
            ['slug' => 'kumasi'],
            ['name' => 'Kumasi', 'country_id' => $ghana->id]
        );

        $lagos = City::firstOrCreate(
            ['slug' => 'lagos'],
            ['name' => 'Lagos', 'country_id' => $nigeria->id]
        );

        $abuja = City::firstOrCreate(
            ['slug' => 'abuja'],
            ['name' => 'Abuja', 'country_id' => $nigeria->id]
        );

        // 4. Neighbourhoods
        $citiesAndQuartiers = [
            [$douala, ['Bonapriso', 'Akwa', 'Bonanjo', 'Deido', 'Makepe', 'Kotto', 'Bali']],
            [$yaounde, ['Bastos', 'Biyem-Assi', 'Mokolo', 'Omnisports', 'Santa Barbara', 'Emana']],
            [$abidjan, ['Cocody', 'Plateau', 'Marcory', 'Yopougon', 'Deux Plateaux', 'Riviera', 'Treichville']],
            [$dakar, ['Almadies', 'Dakar Plateau', 'Ngor', 'Mermoz', 'Ouakam', 'Fann', 'Point E']],
            [$libreville, ['Louis', 'Batterie IV', 'Sablière', 'Akanda', 'Glass', 'Oloumi']],
            [$paris, ['8e Arrondissement', '16e Arrondissement', 'Le Marais', 'Montmartre', 'Saint-Germain']],
            [$accra, ['Osu', 'East Legon', 'Cantonments', 'Airport Residential', 'Labone', 'Dzorwulu']],
            [$kumasi, ['Adum', 'Bantama', 'Nhyiaeso', 'Asokwa']],
            [$lagos, ['Victoria Island', 'Lekki', 'Ikoyi', 'Ikeja', 'Surulere', 'Yaba']],
            [$abuja, ['Maitama', 'Asokoro', 'Wuse', 'Garki', 'Jabi', 'Guzape']],
        ];

        foreach ($citiesAndQuartiers as [$cityModel, $quartiers]) {
            foreach ($quartiers as $q) {
                $baseSlug = Str::slug($q);
                $existing = Neighbourhood::where('slug', $baseSlug)->first();
                $slug = ($existing && $existing->city_id !== $cityModel->id)
                    ? Str::slug($cityModel->slug.'-'.$q)
                    : $baseSlug;

                Neighbourhood::firstOrCreate(
                    ['slug' => $slug],
                    ['name' => $q, 'city_id' => $cityModel->id]
                );
            }
        }

        // 5. Operators
        Operator::firstOrCreate(
            ['code' => 'orange_cm'],
            ['name' => 'Orange Cameroun', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_cm'],
            ['name' => 'MTN Cameroun', 'country_id' => $cameroun->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'orange_ci'],
            ['name' => 'Orange CI', 'country_id' => $cotedivoire->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_ci'],
            ['name' => 'MTN CI', 'country_id' => $cotedivoire->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'orange_sn'],
            ['name' => 'Orange Sénégal', 'country_id' => $senegal->id, 'logo_url' => 'https://example.com/orange.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_gh'],
            ['name' => 'MTN Ghana', 'country_id' => $ghana->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'telecel_gh'],
            ['name' => 'Telecel Ghana', 'country_id' => $ghana->id, 'logo_url' => 'https://example.com/telecel.png']
        );

        Operator::firstOrCreate(
            ['code' => 'mtn_ng'],
            ['name' => 'MTN Nigeria', 'country_id' => $nigeria->id, 'logo_url' => 'https://example.com/mtn.png']
        );

        Operator::firstOrCreate(
            ['code' => 'airtel_ng'],
            ['name' => 'Airtel Nigeria', 'country_id' => $nigeria->id, 'logo_url' => 'https://example.com/airtel.png']
        );
    }
}
