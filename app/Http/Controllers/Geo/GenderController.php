<?php

namespace App\Http\Controllers\Geo;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class GenderController extends Controller
{
    /**
     * Liste des genres pris en charge par l'application (AU-07).
     */
    public function index(): JsonResponse
    {
        $genders = [
            [
                'value' => Gender::Woman->value,
                'label' => 'Une femme',
                'label_fr' => 'Une femme',
                'label_en' => 'A woman',
            ],
            [
                'value' => Gender::Man->value,
                'label' => 'Un homme',
                'label_fr' => 'Un homme',
                'label_en' => 'A man',
            ],
            [
                'value' => Gender::Other->value,
                'label' => 'Autre',
                'label_fr' => 'Autre',
                'label_en' => 'Other',
            ],
            [
                'value' => Gender::Undisclosed->value,
                'label' => 'Je préfère ne pas dire',
                'label_fr' => 'Je préfère ne pas dire',
                'label_en' => 'I would rather not say',
            ],
        ];

        return response()->json($genders);
    }
}
