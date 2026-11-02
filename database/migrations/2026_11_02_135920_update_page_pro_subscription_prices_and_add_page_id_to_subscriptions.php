<?php

use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 1. Ajouter page_id sur subscriptions
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
            $table->index(['page_id', 'state']);
        });

        // 2. Mettre à jour les tarifs du produit page_pro dans subscription_prices
        $pagePro = SubscriptionProduct::where('code', 'page_pro')->first();
        if ($pagePro) {
            // Désactiver l'ancien tarif one_off s'il existe
            SubscriptionPrice::where('product_id', $pagePro->id)
                ->where('cadence', 'one_off')
                ->update(['is_active' => false]);

            // Tarif mensuel : 15 000 FCFA
            SubscriptionPrice::updateOrCreate(
                ['product_id' => $pagePro->id, 'cadence' => 'monthly'],
                [
                    'price_amount' => 15000.00,
                    'currency' => 'XAF',
                    'duration_days' => 30,
                    'savings_description' => 'Facturation mensuelle sans engagement',
                    'is_active' => true,
                    'effective_from' => now()->subMonth(),
                ]
            );

            // Tarif annuel : 150 000 FCFA (2 mois offerts)
            SubscriptionPrice::updateOrCreate(
                ['product_id' => $pagePro->id, 'cadence' => 'yearly'],
                [
                    'price_amount' => 150000.00,
                    'currency' => 'XAF',
                    'duration_days' => 365,
                    'savings_description' => '2 mois offerts par an',
                    'is_active' => true,
                    'effective_from' => now()->subMonth(),
                ]
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['page_id', 'state']);
            $table->dropForeign(['page_id']);
            $table->dropColumn('page_id');
        });
    }
};
