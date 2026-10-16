<?php

namespace App\Http\Controllers\Search;

use App\Http\Controllers\Controller;
use App\Http\Resources\Reel\SoundResource;
use App\Http\Resources\Search\EmergingCreatorResource;
use App\Http\Resources\Search\HashtagSearchResultResource;
use App\Services\Search\TrendsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrendsController extends Controller
{
    public function __construct(
        protected TrendsService $trendsService
    ) {}

    /**
     * Endpoint unifié des tendances (RC-11) combinant top hashtags, musiques et créateurs émergents.
     *
     * GET /api/trends
     */
    public function index(Request $request): JsonResponse
    {
        $trends = $this->trendsService->getTrends($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'top_hashtags' => HashtagSearchResultResource::collection($trends['top_hashtags']),
                'trending_sounds' => SoundResource::collection($trends['trending_sounds']),
                'emerging_creators' => EmergingCreatorResource::collection($trends['emerging_creators']),
            ],
        ]);
    }
}
