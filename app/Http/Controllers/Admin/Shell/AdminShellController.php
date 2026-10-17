<?php

namespace App\Http\Controllers\Admin\Shell;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminCounterService;
use App\Services\Admin\AdminCountryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminShellController extends Controller
{
    public function __construct(
        protected AdminCounterService $counterService,
        protected AdminCountryService $countryService,
    ) {}

    /**
     * GET /shell/counters
     * Retourne les compteurs de navigation administrative filtrés par permissions.
     */
    public function counters(Request $request): JsonResponse
    {
        $operator = $request->user();

        $data = $this->counterService->getCounters($operator);

        return response()->json($data, 200);
    }

    /**
     * GET /shell/countries
     * Fournit les pays couverts par BANGO avec statistiques autorisées.
     */
    public function countries(Request $request): JsonResponse
    {
        $operator = $request->user();

        $data = $this->countryService->getCoveredCountries($operator);

        return response()->json($data, 200);
    }
}
