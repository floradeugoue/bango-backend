<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\SubscriptionPriceResource;
use App\Http\Resources\SubscriptionProductResource;
use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SubscriptionCatalogController extends Controller
{
    /**
     * List all products (active and inactive) for admin back-office.
     */
    public function index(): AnonymousResourceCollection
    {
        $products = SubscriptionProduct::with('prices')
            ->orderBy('display_order')
            ->get();

        return SubscriptionProductResource::collection($products);
    }

    /**
     * Create a new subscription product offer.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:50', 'unique:subscription_products,code'],
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'badge_type' => ['nullable', 'string', 'in:landlord,agent,page,none'],
            'target_role' => ['nullable', 'string', 'max:50'],
            'features' => ['nullable', 'array'],
            'is_active' => ['nullable', 'boolean'],
            'display_order' => ['nullable', 'integer'],
        ]);

        $product = SubscriptionProduct::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Offre créée avec succès.',
            'data' => new SubscriptionProductResource($product),
        ], 201);
    }

    /**
     * Show a product with its full price history.
     */
    public function show(int $id): JsonResponse|SubscriptionProductResource
    {
        $product = SubscriptionProduct::with('prices')->find($id);

        if (! $product) {
            return response()->json(['message' => 'Produit introuvable.'], 404);
        }

        return new SubscriptionProductResource($product);
    }

    /**
     * Update product details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $product = SubscriptionProduct::find($id);

        if (! $product) {
            return response()->json(['message' => 'Produit introuvable.'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'badge_type' => ['nullable', 'string', 'in:landlord,agent,page,none'],
            'target_role' => ['nullable', 'string', 'max:50'],
            'features' => ['nullable', 'array'],
            'is_active' => ['sometimes', 'boolean'],
            'display_order' => ['sometimes', 'integer'],
        ]);

        $product->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Offre mise à jour avec succès.',
            'data' => new SubscriptionProductResource($product->fresh('prices')),
        ]);
    }

    /**
     * Add a new price/plan to a product.
     */
    public function addPrice(Request $request, int $id): JsonResponse
    {
        $product = SubscriptionProduct::find($id);

        if (! $product) {
            return response()->json(['message' => 'Produit introuvable.'], 404);
        }

        $validated = $request->validate([
            'cadence' => ['required', 'string', 'in:monthly,yearly,one_off,cpm'],
            'price_amount' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'duration_days' => ['required', 'integer', 'min:1'],
            'savings_description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_until' => ['nullable', 'date', 'after:effective_from'],
        ]);

        $price = $product->prices()->create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Nouveau tarif ajouté avec succès.',
            'data' => new SubscriptionPriceResource($price),
        ], 201);
    }

    /**
     * Update an existing price/plan.
     */
    public function updatePrice(Request $request, int $priceId): JsonResponse
    {
        $price = SubscriptionPrice::find($priceId);

        if (! $price) {
            return response()->json(['message' => 'Tarif introuvable.'], 404);
        }

        $validated = $request->validate([
            'price_amount' => ['sometimes', 'numeric', 'min:0'],
            'savings_description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'effective_from' => ['sometimes', 'date'],
            'effective_until' => ['nullable', 'date'],
        ]);

        $price->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Tarif mis à jour avec succès.',
            'data' => new SubscriptionPriceResource($price),
        ]);
    }
}
