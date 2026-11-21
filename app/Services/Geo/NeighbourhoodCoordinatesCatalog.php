<?php

namespace App\Services\Geo;

use Illuminate\Support\Str;

class NeighbourhoodCoordinatesCatalog
{
    /**
     * Coordonnées géographiques (centre de quartier) vérifiées pour les villes ouvertes et de référence.
     * Indexées par slug de ville puis slug de quartier (normalisés sans accents).
     *
     * @return array<string, array<string, array{lat: float, lng: float}>>
     */
    public static function all(): array
    {
        return [
            'douala' => [
                'bonapriso' => ['lat' => 4.0256, 'lng' => 9.7022],
                'akwa' => ['lat' => 4.0520, 'lng' => 9.7010],
                'bonanjo' => ['lat' => 4.0435, 'lng' => 9.6890],
                'bali' => ['lat' => 4.0380, 'lng' => 9.6930],
                'deido' => ['lat' => 4.0670, 'lng' => 9.7120],
                'makepe' => ['lat' => 4.0840, 'lng' => 9.7430],
                'kotto' => ['lat' => 4.0950, 'lng' => 9.7560],
                'logpom' => ['lat' => 4.0875, 'lng' => 9.7664],
                'logbessou' => ['lat' => 4.0808, 'lng' => 9.7949],
                'bonamoussadi' => ['lat' => 4.0944, 'lng' => 9.7394],
                'bonaberi' => ['lat' => 4.0779, 'lng' => 9.6759],
                'ndogbong' => ['lat' => 4.0645, 'lng' => 9.7521],
                'ndogpassi' => ['lat' => 4.0101, 'lng' => 9.7567],
                'new-bell' => ['lat' => 4.0315, 'lng' => 9.7058],
                'bepanda' => ['lat' => 4.0541, 'lng' => 9.7261],
                'nyalla' => ['lat' => 4.0334, 'lng' => 9.7744],
                'yassa' => ['lat' => 3.9913, 'lng' => 9.8104],
            ],
            'yaounde' => [
                'bastos' => ['lat' => 3.8900, 'lng' => 11.5130],
                'omnisports' => ['lat' => 3.8780, 'lng' => 11.5340],
                'biyem-assi' => ['lat' => 3.8405, 'lng' => 11.4865],
                'mokolo' => ['lat' => 3.8833, 'lng' => 11.5000],
                'santa-barbara' => ['lat' => 3.9143, 'lng' => 11.5368],
                'emana' => ['lat' => 3.9167, 'lng' => 11.5167],
                'mvan' => ['lat' => 3.8167, 'lng' => 11.5167],
                'odza' => ['lat' => 3.7990, 'lng' => 11.5212],
                'nlongkak' => ['lat' => 3.8916, 'lng' => 11.5245],
                'essos' => ['lat' => 3.8700, 'lng' => 11.5300],
                'mimboman' => ['lat' => 3.8672, 'lng' => 11.5493],
                'ngousso' => ['lat' => 3.8953, 'lng' => 11.5504],
                'mendong' => ['lat' => 3.8300, 'lng' => 11.4800],
                'etoudi' => ['lat' => 3.9167, 'lng' => 11.5167],
                'nsam' => ['lat' => 3.8350, 'lng' => 11.5100],
                'tsinga' => ['lat' => 3.8900, 'lng' => 11.5050],
                'obili' => ['lat' => 3.8573, 'lng' => 11.4925],
                'melen' => ['lat' => 3.8667, 'lng' => 11.4960],
            ],
            'abidjan' => [
                'cocody' => ['lat' => 5.3564, 'lng' => -3.9883],
                'plateau' => ['lat' => 5.3281, 'lng' => -4.0213],
                'le-plateau' => ['lat' => 5.3281, 'lng' => -4.0213],
                'marcory' => ['lat' => 5.3000, 'lng' => -3.9833],
                'yopougon' => ['lat' => 5.3352, 'lng' => -4.0758],
                'deux-plateaux' => ['lat' => 5.3670, 'lng' => -4.0100],
                '2-plateaux' => ['lat' => 5.3670, 'lng' => -4.0100],
                'riviera' => ['lat' => 5.3500, 'lng' => -3.9750],
                'treichville' => ['lat' => 5.3060, 'lng' => -4.0081],
                'angre' => ['lat' => 5.3862, 'lng' => -3.9827],
                'koumassi' => ['lat' => 5.2997, 'lng' => -3.9529],
                'abobo' => ['lat' => 5.4161, 'lng' => -4.0159],
                'adjame' => ['lat' => 5.3651, 'lng' => -4.0236],
                'attecoube' => ['lat' => 5.3333, 'lng' => -4.0333],
                'port-bouet' => ['lat' => 5.2667, 'lng' => -3.9000],
                'bingerville' => ['lat' => 5.3500, 'lng' => -3.9000],
                'zone-4' => ['lat' => 5.2907, 'lng' => -3.9814],
            ],
            'dakar' => [
                'almadies' => ['lat' => 14.7442, 'lng' => -17.5164],
                'dakar-plateau' => ['lat' => 14.6690, 'lng' => -17.4363],
                'plateau' => ['lat' => 14.6690, 'lng' => -17.4363],
                'ouakam' => ['lat' => 14.7253, 'lng' => -17.4906],
                'mermoz' => ['lat' => 14.7042, 'lng' => -17.4739],
                'ngor' => ['lat' => 14.7550, 'lng' => -17.5150],
                'fann' => ['lat' => 14.6940, 'lng' => -17.4616],
                'point-e' => ['lat' => 14.6940, 'lng' => -17.4616],
                'sacre-coeur' => ['lat' => 14.7134, 'lng' => -17.4623],
                'medina' => ['lat' => 14.6850, 'lng' => -17.4494],
                'yoff' => ['lat' => 14.7604, 'lng' => -17.4682],
                'parcelles-assainies' => ['lat' => 14.7569, 'lng' => -17.4347],
                'liberte' => ['lat' => 14.7117, 'lng' => -17.4611],
                'hann-maristes' => ['lat' => 14.7375, 'lng' => -17.4266],
                'pikine' => ['lat' => 14.7589, 'lng' => -17.3938],
                'guediawaye' => ['lat' => 14.7754, 'lng' => -17.3997],
                'rufisque' => ['lat' => 14.7155, 'lng' => -17.2709],
                'mamelles' => ['lat' => 14.7228, 'lng' => -17.4989],
            ],
            'libreville' => [
                'louis' => ['lat' => 0.4106, 'lng' => 9.4328],
                'batterie-iv' => ['lat' => 0.4232, 'lng' => 9.4251],
                'sabliere' => ['lat' => 0.5167, 'lng' => 9.3667],
                'la-sabliere' => ['lat' => 0.5167, 'lng' => 9.3667],
                'akanda' => ['lat' => 0.5164, 'lng' => 9.3882],
                'glass' => ['lat' => 0.3950, 'lng' => 9.4520],
                'oloumi' => ['lat' => 0.3798, 'lng' => 9.4677],
                'nzeng-ayong' => ['lat' => 0.4222, 'lng' => 9.4665],
                'owendo' => ['lat' => 0.2912, 'lng' => 9.5046],
                'mont-bouet' => ['lat' => 0.3920, 'lng' => 9.4580],
                'awendje' => ['lat' => 0.3800, 'lng' => 9.4700],
                'lalala' => ['lat' => 0.3600, 'lng' => 9.4650],
                'charbonnages' => ['lat' => 0.4350, 'lng' => 9.4350],
                'angondje' => ['lat' => 0.5000, 'lng' => 9.4333],
            ],
            'cotonou' => [
                'haie-vive' => ['lat' => 6.3570, 'lng' => 2.4170],
                'cadjehoun' => ['lat' => 6.3620, 'lng' => 2.4080],
                'fidjrosse' => ['lat' => 6.3680, 'lng' => 2.3730],
                'akpakpa' => ['lat' => 6.3667, 'lng' => 2.4500],
                'ganhi' => ['lat' => 6.3550, 'lng' => 2.4333],
                'cocotiers' => ['lat' => 6.3580, 'lng' => 2.3890],
                'agla' => ['lat' => 6.3720, 'lng' => 2.3730],
                'godomey' => ['lat' => 6.3760, 'lng' => 2.3350],
            ],
            'lome' => [
                'tokoin' => ['lat' => 6.1550, 'lng' => 1.2220],
                'be' => ['lat' => 6.1400, 'lng' => 1.2460],
                'adidogome' => ['lat' => 6.1850, 'lng' => 1.1680],
                'agoe' => ['lat' => 6.2167, 'lng' => 1.1833],
                'agoe-nyive' => ['lat' => 6.2167, 'lng' => 1.1833],
                'nyekonakpoe' => ['lat' => 6.1347, 'lng' => 1.2024],
                'hedzranawoe' => ['lat' => 6.1550, 'lng' => 1.2330],
                'baguida' => ['lat' => 6.1784, 'lng' => 1.3304],
                'kegue' => ['lat' => 6.2064, 'lng' => 1.2375],
            ],
            'accra' => [
                'osu' => ['lat' => 5.5573, 'lng' => -0.1864],
                'east-legon' => ['lat' => 5.6360, 'lng' => -0.1590],
                'cantonments' => ['lat' => 5.5861, 'lng' => -0.1764],
                'airport-residential' => ['lat' => 5.6064, 'lng' => -0.1837],
                'airport-residential-area' => ['lat' => 5.6064, 'lng' => -0.1837],
                'labone' => ['lat' => 5.5670, 'lng' => -0.1740],
                'dzorwulu' => ['lat' => 5.6147, 'lng' => -0.2008],
            ],
            'kumasi' => [
                'adum' => ['lat' => 6.6896, 'lng' => -1.6188],
                'bantama' => ['lat' => 6.6960, 'lng' => -1.6338],
                'nhyiaeso' => ['lat' => 6.6735, 'lng' => -1.6166],
                'asokwa' => ['lat' => 6.6740, 'lng' => -1.6030],
            ],
            'lagos' => [
                'victoria-island' => ['lat' => 6.4291, 'lng' => 3.4238],
                'lekki' => ['lat' => 6.4463, 'lng' => 3.4772],
                'ikoyi' => ['lat' => 6.4496, 'lng' => 3.4328],
                'ikeja' => ['lat' => 6.5965, 'lng' => 3.3421],
                'surulere' => ['lat' => 6.5000, 'lng' => 3.3500],
                'yaba' => ['lat' => 6.5158, 'lng' => 3.3889],
            ],
            'abuja' => [
                'maitama' => ['lat' => 9.0667, 'lng' => 7.4833],
                'asokoro' => ['lat' => 9.0523, 'lng' => 7.5262],
                'wuse' => ['lat' => 9.0659, 'lng' => 7.4758],
                'garki' => ['lat' => 9.0286, 'lng' => 7.4797],
                'jabi' => ['lat' => 9.0765, 'lng' => 7.4251],
                'guzape' => ['lat' => 9.0333, 'lng' => 7.5167],
            ],
            'paris' => [
                '8e-arrondissement' => ['lat' => 48.8744, 'lng' => 2.3106],
                '16e-arrondissement' => ['lat' => 48.8637, 'lng' => 2.2769],
                'le-marais' => ['lat' => 48.8575, 'lng' => 2.3584],
                'montmartre' => ['lat' => 48.8867, 'lng' => 2.3431],
                'saint-germain' => ['lat' => 48.8540, 'lng' => 2.3338],
            ],
        ];
    }

    /**
     * Recherche les coordonnées d'un quartier par ville et nom/slug (insensible à la casse et aux accents).
     *
     * @return array{lat: float, lng: float}|null
     */
    public static function lookup(string $cityNameOrSlug, string $neighbourhoodNameOrSlug): ?array
    {
        $catalog = self::all();
        $citySlug = Str::slug($cityNameOrSlug);

        if (! isset($catalog[$citySlug])) {
            return null;
        }

        $cityEntries = $catalog[$citySlug];
        $neighbourhoodSlug = Str::slug($neighbourhoodNameOrSlug);

        if (isset($cityEntries[$neighbourhoodSlug])) {
            return $cityEntries[$neighbourhoodSlug];
        }

        // Gestion des slugs préfixés par la ville (ex: "abidjan-plateau", "cm-douala-bonapriso")
        $cityPrefix = $citySlug.'-';
        if (str_starts_with($neighbourhoodSlug, $cityPrefix)) {
            $stripped = substr($neighbourhoodSlug, strlen($cityPrefix));
            if (isset($cityEntries[$stripped])) {
                return $cityEntries[$stripped];
            }
        }

        return null;
    }
}
