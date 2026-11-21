<?php

namespace App\Exceptions\Geo;

use Exception;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NeighbourhoodNotVerifiedException extends Exception implements ShouldntReport
{
    public const ERROR_CODE = 'NEIGHBOURHOOD_NOT_VERIFIED';

    public function __construct(
        public readonly string $reason = 'too_far',
        public readonly float $maxDistanceKm = 3.0,
        ?string $message = null,
    ) {
        parent::__construct(
            $message ?? 'Ta position actuelle doit être à 3 km au plus du centre du quartier choisi pour le confirmer.',
            422
        );
    }

    public function render(Request $request): JsonResponse
    {
        $locale = $request->user()?->locale ?? 'fr';
        $message = $this->localizedMessage($locale);

        return response()->json([
            'code' => self::ERROR_CODE,
            'error' => self::ERROR_CODE,
            'message' => $message,
            'errors' => [
                'neighbourhood' => [$message],
            ],
            'max_distance_km' => $this->maxDistanceKm,
        ], 422);
    }

    protected function localizedMessage(string $locale): string
    {
        if ($locale === 'en') {
            $distanceLabel = rtrim(rtrim(number_format($this->maxDistanceKm, 2, '.', ''), '0'), '.');

            return match ($this->reason) {
                'coordinates_missing' => 'Your current location is required to confirm your neighbourhood.',
                'neighbourhood_unknown' => 'The selected neighbourhood could not be verified for this city.',
                'neighbourhood_coordinates_missing' => 'This neighbourhood cannot be verified by location yet.',
                default => "Your current location must be within {$distanceLabel} km of the selected neighbourhood center.",
            };
        }

        $distanceLabel = rtrim(rtrim(number_format($this->maxDistanceKm, 2, ',', ''), '0'), ',');

        return match ($this->reason) {
            'coordinates_missing' => 'Ta position actuelle est requise pour confirmer ton quartier.',
            'neighbourhood_unknown' => 'Le quartier choisi ne peut pas être vérifié pour cette ville.',
            'neighbourhood_coordinates_missing' => 'Ce quartier ne dispose pas encore de coordonnées permettant sa vérification.',
            default => "Ta position actuelle doit être à {$distanceLabel} km au plus du centre du quartier choisi pour le confirmer.",
        };
    }
}
