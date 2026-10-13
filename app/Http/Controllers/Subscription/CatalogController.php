<?php

namespace App\Http\Controllers\Subscription;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionProductResource;
use App\Models\Subscription\SubscriptionProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CatalogController extends Controller
{
    /**
     * Display public catalog of active subscription products and plans.
     */
    public function index(): AnonymousResourceCollection
    {
        $products = SubscriptionProduct::with('activePrices')
            ->where('is_active', true)
            ->orderBy('display_order')
            ->get();

        return SubscriptionProductResource::collection($products);
    }

    /**
     * Display a specific catalog offer by code or ID.
     */
    public function show(string $code): JsonResponse|SubscriptionProductResource
    {
        $product = SubscriptionProduct::with('activePrices')
            ->where('is_active', true)
            ->where(function ($query) use ($code) {
                if (is_numeric($code)) {
                    $query->where('id', (int) $code)->orWhere('code', $code);
                } else {
                    $query->where('code', $code);
                }
            })
            ->first();

        if (! $product) {
            return response()->json([
                'kind' => 'not-found',
                'message' => 'Cette offre n\'existe pas dans le catalogue.',
            ], 404);
        }

        return new SubscriptionProductResource($product);
    }
}
