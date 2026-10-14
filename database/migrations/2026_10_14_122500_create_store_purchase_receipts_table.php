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
        Schema::create('store_purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->string('provider', 32); // apple, google
            $table->string('store_product_id', 128); // e.g. cm.bango.subscription.landlord.monthly
            $table->string('original_transaction_id', 128)->nullable(); // Apple original transaction ID
            $table->string('purchase_token_hash', 64)->unique(); // SHA-256 hash anti-rejeu du reçu/jeton
            $table->string('verification_status', 32)->default('pending'); // pending, verified, revoked
            $table->string('environment', 16)->default('production'); // sandbox, production
            $table->json('store_payload')->nullable(); // Métadonnées expurgées
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'store_product_id']);
            $table->index(['provider', 'original_transaction_id']);
            $table->index('verification_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_purchase_receipts');
    }
};
