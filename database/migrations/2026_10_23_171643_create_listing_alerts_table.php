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
        Schema::create('listing_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->string('mode')->nullable(); // rent, sale, flatshare
            $table->string('country_code', 2)->nullable();
            $table->string('city')->nullable();
            $table->json('neighbourhoods')->nullable();
            $table->json('kinds')->nullable();
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();
            $table->json('bedrooms')->nullable();
            $table->json('showers')->nullable();
            $table->decimal('area_min', 10, 2)->nullable();
            $table->decimal('area_max', 10, 2)->nullable();
            $table->json('amenities')->nullable();
            $table->boolean('verified_only')->default(false);
            $table->boolean('agent_visited_only')->default(false);
            $table->boolean('utilities_included_only')->default(false);
            $table->string('frequency')->default('instant'); // instant, daily, weekly
            $table->boolean('push')->default(true);
            $table->boolean('email')->default(false);
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['city', 'mode']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_alerts');
    }
};
