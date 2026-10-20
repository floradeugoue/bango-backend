<?php

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
        Schema::create('payment_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 64)->unique();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();

            // Domaine métier / produit concerné
            $table->string('service_type', 32); // subscription, certification, boost, visit
            $table->string('payable_type', 128)->nullable();
            $table->string('payable_id', 64)->nullable();
            $table->index(['payable_type', 'payable_id']);
            $table->foreignId('product_id')->nullable()->constrained('subscription_products')->restrictOnDelete();
            $table->foreignId('price_id')->nullable()->constrained('subscription_prices')->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('subscriptions')->restrictOnDelete();

            // Passerelle & canal
            $table->string('provider', 32); // monetbil, flutterwave, apple, google, credit
            $table->string('channel', 32);  // mobile_money, apple_iap, google_play, credit_wallet, card
            $table->string('currency', 4)->default('XAF');

            // Précision monétaire exacte (NUMERIC/DECIMAL 12, 2)
            $table->decimal('amount', 12, 2);
            $table->decimal('fee_amount', 12, 2)->default(0.00);
            $table->decimal('tax_amount', 12, 2)->default(0.00);
            $table->decimal('total_amount', 12, 2);
            $table->decimal('credit_used', 12, 2)->default(0.00);
            $table->decimal('amount_charged', 12, 2);

            // Statut contrôlé & identifiants externes
            $table->string('status', 32)->default('pending');
            $table->string('external_reference', 128)->nullable();
            $table->string('idempotency_key', 128)->unique();

            // Données d'audit et métadonnées expurgées (aucun secret ni PIN)
            $table->json('metadata')->nullable();

            // Horodatages métier
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            // Index de performance et recherche
            $table->index(['user_id', 'status']);
            $table->index(['provider', 'external_reference']);
            $table->index(['service_type', 'status']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_transactions');
    }
};
