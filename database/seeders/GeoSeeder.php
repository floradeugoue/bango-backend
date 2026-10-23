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

        $kes = Currency::firstOrCreate(
            ['code' => 'KES'],
            ['name' => 'Kenyan Shilling', 'symbol' => 'KSh']
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
                'is_active' => true,
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
                'is_active' => true,
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
                'is_active' => true,
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
                'is_active' => true,
            ]
        );

        $benin = Country::updateOrCreate(
            ['code' => 'BJ'],
            [
                'name' => 'Bénin',
                'name_en' => 'Benin',
                'iso2' => 'BJ',
                'iso3' => 'BEN',
                'phone_code' => '+229',
                'currency_id' => $xof->id,
                'is_active' => true,
            ]
        );

        $togo = Country::updateOrCreate(
            ['code' => 'TG'],
            [
                'name' => 'Togo',
                'name_en' => 'Togo',
                'iso2' => 'TG',
                'iso3' => 'TGO',
                'phone_code' => '+228',
                'currency_id' => $xof->id,
                'is_active' => true,
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
                'is_active' => true,
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
                'is_active' => true,
            ]
        );

        $kenya = Country::updateOrCreate(
            ['code' => 'KE'],
            [
                'name' => 'Kenya',
                'name_en' => 'Kenya',
                'iso2' => 'KE',
                'iso3' => 'KEN',
                'phone_code' => '+254',
                'currency_id' => $kes->id,
                'is_active' => true,
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
                'is_active' => false, // Inactif pour AU-08 (réservé aux 8 pays d'Afrique subsaharienne)
            ]
        );

        // 3. Cities (avec coordonnées géographiques officielles)
        $douala = City::updateOrCreate(
            ['slug' => 'douala'],
            ['name' => 'Douala', 'country_id' => $cameroun->id, 'lat' => 4.0511, 'lng' => 9.7679]
        );

        $yaounde = City::updateOrCreate(
            ['slug' => 'yaounde'],
            ['name' => 'Yaoundé', 'country_id' => $cameroun->id, 'lat' => 3.8480, 'lng' => 11.5021]
        );

        $abidjan = City::updateOrCreate(
            ['slug' => 'abidjan'],
            ['name' => 'Abidjan', 'country_id' => $cotedivoire->id, 'lat' => 5.3600, 'lng' => -4.0083]
        );

        $dakar = City::updateOrCreate(
            ['slug' => 'dakar'],
            ['name' => 'Dakar', 'country_id' => $senegal->id, 'lat' => 14.6928, 'lng' => -17.4467]
        );

        $libreville = City::updateOrCreate(
            ['slug' => 'libreville'],
            ['name' => 'Libreville', 'country_id' => $gabon->id, 'lat' => 0.4162, 'lng' => 9.4673]
        );

        $cotonou = City::updateOrCreate(
            ['slug' => 'cotonou'],
            ['name' => 'Cotonou', 'country_id' => $benin->id, 'lat' => 6.3703, 'lng' => 2.3912]
        );

        $lome = City::updateOrCreate(
            ['slug' => 'lome'],
            ['name' => 'Lomé', 'country_id' => $togo->id, 'lat' => 6.1725, 'lng' => 1.2314]
        );

        $accra = City::updateOrCreate(
            ['slug' => 'accra'],
            ['name' => 'Accra', 'country_id' => $ghana->id, 'lat' => 5.6037, 'lng' => -0.1870]
        );

        $kumasi = City::updateOrCreate(
            ['slug' => 'kumasi'],
            ['name' => 'Kumasi', 'country_id' => $ghana->id, 'lat' => 6.6885, 'lng' => -1.6244]
        );

        $lagos = City::updateOrCreate(
            ['slug' => 'lagos'],
            ['name' => 'Lagos', 'country_id' => $nigeria->id, 'lat' => 6.5244, 'lng' => 3.3792]
        );

        $abuja = City::updateOrCreate(
            ['slug' => 'abuja'],
            ['name' => 'Abuja', 'country_id' => $nigeria->id, 'lat' => 9.0765, 'lng' => 7.3986]
        );

        $paris = City::updateOrCreate(
            ['slug' => 'paris'],
            ['name' => 'Paris', 'country_id' => $france->id, 'lat' => 48.8566, 'lng' => 2.3522]
        );

        // 4. Neighbourhoods
        $knownQuartersCoords = [
            'Dakar/Almadies' => ['lat' => 14.7442, 'lng' => -17.5164],
            'Dakar/Dakar Plateau' => ['lat' => 14.6690, 'lng' => -17.4363],
            'Dakar/Ouakam' => ['lat' => 14.7253, 'lng' => -17.4906],
            'Dakar/Mermoz' => ['lat' => 14.7042, 'lng' => -17.4739],
            'Dakar/Ngor' => ['lat' => 14.7550, 'lng' => -17.5150],
            'Douala/Bonapriso' => ['lat' => 4.0256, 'lng' => 9.7022],
            'Douala/Akwa' => ['lat' => 4.0520, 'lng' => 9.7010],
            'Douala/Bonanjo' => ['lat' => 4.0435, 'lng' => 9.6890],
            'Douala/Bali' => ['lat' => 4.0380, 'lng' => 9.6930],
            'Douala/Deido' => ['lat' => 4.0670, 'lng' => 9.7120],
            'Douala/Makepe' => ['lat' => 4.0840, 'lng' => 9.7430],
            'Douala/Kotto' => ['lat' => 4.0950, 'lng' => 9.7560],
            'Yaoundé/Bastos' => ['lat' => 3.8900, 'lng' => 11.5130],
            'Yaoundé/Omnisports' => ['lat' => 3.8780, 'lng' => 11.5340],
            'Cotonou/Haie Vive' => ['lat' => 6.3570, 'lng' => 2.4170],
            'Cotonou/Cadjèhoun' => ['lat' => 6.3620, 'lng' => 2.4080],
            'Cotonou/Fidjrossè' => ['lat' => 6.3680, 'lng' => 2.3730],
            'Lomé/Tokoin' => ['lat' => 6.1550, 'lng' => 1.2220],
            'Lomé/Bè' => ['lat' => 6.1400, 'lng' => 1.2460],
            'Lomé/Adidogomé' => ['lat' => 6.1850, 'lng' => 1.1680],
        ];

        $citiesAndQuartiers = [
            [$douala, ['Bonapriso', 'Akwa', 'Bonanjo', 'Deido', 'Makepe', 'Kotto', 'Bali']],
            [$yaounde, ['Bastos', 'Biyem-Assi', 'Mokolo', 'Omnisports', 'Santa Barbara', 'Emana']],
            [$abidjan, ['Cocody', 'Plateau', 'Marcory', 'Yopougon', 'Deux Plateaux', 'Riviera', 'Treichville']],
            [$dakar, ['Almadies', 'Dakar Plateau', 'Ngor', 'Mermoz', 'Ouakam', 'Fann', 'Point E']],
            [$libreville, ['Louis', 'Batterie IV', 'Sablière', 'Akanda', 'Glass', 'Oloumi']],
            [$cotonou, ['Haie Vive', 'Cadjèhoun', 'Fidjrossè']],
            [$lome, ['Tokoin', 'Bè', 'Adidogomé']],
            [$accra, ['Osu', 'East Legon', 'Cantonments', 'Airport Residential', 'Labone', 'Dzorwulu']],
            [$kumasi, ['Adum', 'Bantama', 'Nhyiaeso', 'Asokwa']],
            [$lagos, ['Victoria Island', 'Lekki', 'Ikoyi', 'Ikeja', 'Surulere', 'Yaba']],
            [$abuja, ['Maitama', 'Asokoro', 'Wuse', 'Garki', 'Jabi', 'Guzape']],
            [$paris, ['8e Arrondissement', '16e Arrondissement', 'Le Marais', 'Montmartre', 'Saint-Germain']],
        ];

        foreach ($citiesAndQuartiers as [$cityModel, $quartiers]) {
            foreach ($quartiers as $q) {
                $baseSlug = Str::slug($q);
                $existing = Neighbourhood::where('slug', $baseSlug)->first();
                $slug = ($existing && $existing->city_id !== $cityModel->id)
                    ? Str::slug($cityModel->slug.'-'.$q)
                    : $baseSlug;

                $coords = $knownQuartersCoords["{$cityModel->name}/{$q}"] ?? null;

                Neighbourhood::updateOrCreate(
                    ['slug' => $slug],
                    [
                        'name' => $q,
                        'city_id' => $cityModel->id,
                        'lat' => $coords['lat'] ?? null,
                        'lng' => $coords['lng'] ?? null,
                    ]
                );
            }
        }

        // 5. Operators (avec logos hébergés et préfixes de numérotation Mobile Money)
        // Cameroun
        Operator::updateOrCreate(
            ['code' => 'orange_cm'],
            [
                'name' => 'Orange Cameroun',
                'country_id' => $cameroun->id,
                'logo_url' => '/images/operators/orange.svg',
                'prefixes' => ['69', '655', '656', '657', '658', '659'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'mtn_cm'],
            [
                'name' => 'MTN Cameroun',
                'country_id' => $cameroun->id,
                'logo_url' => '/images/operators/mtn.svg',
                'prefixes' => ['65', '67', '68'],
            ]
        );

        // Côte d'Ivoire
        Operator::updateOrCreate(
            ['code' => 'orange_ci'],
            [
                'name' => 'Orange CI',
                'country_id' => $cotedivoire->id,
                'logo_url' => '/images/operators/orange.svg',
                'prefixes' => ['07'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'mtn_ci'],
            [
                'name' => 'MTN CI',
                'country_id' => $cotedivoire->id,
                'logo_url' => '/images/operators/mtn.svg',
                'prefixes' => ['05'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'moov_ci'],
            [
                'name' => 'Moov Money CI',
                'country_id' => $cotedivoire->id,
                'logo_url' => '/images/operators/moov.svg',
                'prefixes' => ['01'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'wave_ci'],
            [
                'name' => 'Wave CI',
                'country_id' => $cotedivoire->id,
                'logo_url' => '/images/operators/wave.svg',
                'prefixes' => ['01', '05', '07'],
            ]
        );

        // Sénégal
        Operator::updateOrCreate(
            ['code' => 'orange_sn'],
            [
                'name' => 'Orange Sénégal',
                'country_id' => $senegal->id,
                'logo_url' => '/images/operators/orange.svg',
                'prefixes' => ['77', '78'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'wave_sn'],
            [
                'name' => 'Wave Sénégal',
                'country_id' => $senegal->id,
                'logo_url' => '/images/operators/wave.svg',
                'prefixes' => ['77', '78', '76', '70', '75'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'free_sn'],
            [
                'name' => 'Free Money Sénégal',
                'country_id' => $senegal->id,
                'logo_url' => '/images/operators/freemoney.svg',
                'prefixes' => ['76'],
            ]
        );

        // Gabon
        Operator::updateOrCreate(
            ['code' => 'airtel_ga'],
            [
                'name' => 'Airtel Money Gabon',
                'country_id' => $gabon->id,
                'logo_url' => '/images/operators/airtel.svg',
                'prefixes' => ['074', '077'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'moov_ga'],
            [
                'name' => 'Moov Money Gabon',
                'country_id' => $gabon->id,
                'logo_url' => '/images/operators/moov.svg',
                'prefixes' => ['062', '065', '066'],
            ]
        );

        // Bénin
        Operator::updateOrCreate(
            ['code' => 'mtn_bj'],
            [
                'name' => 'MTN Bénin',
                'country_id' => $benin->id,
                'logo_url' => '/images/operators/mtn.svg',
                'prefixes' => ['51', '52', '53', '54', '61', '62', '66', '67', '69', '96', '97'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'moov_bj'],
            [
                'name' => 'Moov Money Bénin',
                'country_id' => $benin->id,
                'logo_url' => '/images/operators/moov.svg',
                'prefixes' => ['94', '95', '98', '99'],
            ]
        );

        // Togo
        Operator::updateOrCreate(
            ['code' => 'tmoney_tg'],
            [
                'name' => 'T-Money',
                'country_id' => $togo->id,
                'logo_url' => '/images/operators/tmoney.svg',
                'prefixes' => ['90', '91', '92', '93', '70'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'moov_tg'],
            [
                'name' => 'Flooz (Moov Togo)',
                'country_id' => $togo->id,
                'logo_url' => '/images/operators/moov.svg',
                'prefixes' => ['96', '97', '98', '99'],
            ]
        );

        // Ghana
        Operator::updateOrCreate(
            ['code' => 'mtn_gh'],
            [
                'name' => 'MTN Ghana',
                'country_id' => $ghana->id,
                'logo_url' => '/images/operators/mtn.svg',
                'prefixes' => ['024', '054', '055', '059', '025'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'telecel_gh'],
            [
                'name' => 'Telecel Ghana',
                'country_id' => $ghana->id,
                'logo_url' => '/images/operators/telecel.svg',
                'prefixes' => ['020', '050'],
            ]
        );

        // Nigéria
        Operator::updateOrCreate(
            ['code' => 'mtn_ng'],
            [
                'name' => 'MTN Nigeria',
                'country_id' => $nigeria->id,
                'logo_url' => '/images/operators/mtn.svg',
                'prefixes' => ['0803', '0806', '0703', '0706', '0813', '0816', '0810', '0814', '0903', '0906'],
            ]
        );

        Operator::updateOrCreate(
            ['code' => 'airtel_ng'],
            [
                'name' => 'Airtel Nigeria',
                'country_id' => $nigeria->id,
                'logo_url' => '/images/operators/airtel.svg',
                'prefixes' => ['0802', '0808', '0708', '0812', '0701', '0902', '0901', '0904'],
            ]
        );

        // Kenya
        Operator::updateOrCreate(
            ['code' => 'mpesa_ke'],
            [
                'name' => 'M-Pesa Safaricom',
                'country_id' => $kenya->id,
                'logo_url' => '/images/operators/mpesa.svg',
                'prefixes' => ['070', '071', '072', '074', '079', '011'],
            ]
        );
    }
}
