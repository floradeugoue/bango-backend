<?php

namespace App\Http\Controllers\Admin\Moderation;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Moderation\ExcludeHashtagRequest;
use App\Http\Requests\Admin\Moderation\RestoreHashtagRequest;
use App\Services\Admin\ModerationTrendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminModerationTrendController extends Controller
{
    public function __construct(
        protected ModerationTrendService $trendService
    ) {}

    /**
     * File des tendances et état de modération des hashtags (GET /moderation/trends).
     */
    public function index(Request $request): JsonResponse
    {
        $trends = $this->trendService->getTrendsWithExclusions($request->user());

        return response()->json([
            'success' => true,
            'data' => $trends,
        ]);
    }

    /**
     * Liste des exclusions de hashtags (GET /moderation/trends/exclusions).
     */
    public function listExclusions(Request $request): JsonResponse
    {
        $activeOnly = $request->has('active') ? filter_var($request->query('active'), FILTER_VALIDATE_BOOLEAN) : null;
        $exclusions = $this->trendService->listExclusions($activeOnly);

        return response()->json([
            'success' => true,
            'data' => $exclusions,
        ]);
    }

    /**
     * Exclusion d'un hashtag des tendances (POST /moderation/trends/exclusions).
     */
    public function exclude(ExcludeHashtagRequest $request): JsonResponse
    {
        $operator = $request->user();
        $exclusion = $this->trendService->excludeHashtag(
            tag: $request->input('hashtag'),
            reason: $request->input('reason'),
            operator: $operator
        );

        return response()->json([
            'success' => true,
            'message' => "Le hashtag '#{$exclusion->hashtag_name}' a été exclu des tendances.",
            'data' => $exclusion,
        ], 201);
    }

    /**
     * Restauration d'un hashtag exclu (POST /moderation/exclusions/restore ou POST /moderation/trends/exclusions/restore).
     */
    public function restore(RestoreHashtagRequest $request): JsonResponse
    {
        $operator = $request->user();
        $exclusion = $this->trendService->restoreHashtag(
            tag: $request->input('hashtag'),
            operator: $operator
        );

        return response()->json([
            'success' => true,
            'message' => "Le hashtag '#{$exclusion->hashtag_name}' a été réintégré aux tendances.",
            'data' => $exclusion,
        ]);
    }
}
