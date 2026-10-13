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
        Schema::create('subscription_products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // landlord, agent, page_pro, listing_boost
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('badge_type')->nullable(); // landlord, agent, page
            $table->string('target_role')->nullable();
            $table->json('features')->nullable(); // Bullets for UI display
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });

        Schema::create('subscription_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('subscription_products')->cascadeOnDelete();
            $table->string('cadence'); // monthly, yearly, one_off
            $table->decimal('price_amount', 12, 2);
            $table->string('currency', 4)->default('XAF');
            $table->unsignedInteger('duration_days'); // 30, 365
            $table->string('savings_description')->nullable(); // "2 mois offerts", etc.
            $table->boolean('is_active')->default(true);
            $table->timestamp('effective_from')->useCurrent();
            $table->timestamp('effective_until')->nullable();
            $table->timestamps();

            $table->index(['product_id', 'is_active', 'cadence']);
            $table->index(['effective_from', 'effective_until']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_prices');
        Schema::dropIfExists('subscription_products');
    }
};
