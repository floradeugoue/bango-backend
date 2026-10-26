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
        Schema::create('campaign_impressions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('shown_at')->useCurrent();
            $table->timestamps();

            $table->index(['campaign_id', 'user_id', 'shown_at']);
            $table->index(['user_id', 'shown_at']);
        });

        Schema::create('campaign_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->timestamp('clicked_at')->useCurrent();
            $table->timestamps();

            $table->index(['campaign_id', 'user_id', 'clicked_at']);
            $table->index(['user_id', 'clicked_at']);
        });

        Schema::create('campaign_results', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // follow, message, profile_visit
            $table->foreignId('impression_id')->nullable()->constrained('campaign_impressions')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id', 'type', 'impression_id']);
            $table->index(['campaign_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_results');
        Schema::dropIfExists('campaign_clicks');
        Schema::dropIfExists('campaign_impressions');
    }
};
