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
        // 1. Contrainte d'unicité sur housing_searches.user_id (garantit l'intégrité de la relation HasOne)
        Schema::table('housing_searches', function (Blueprint $table) {
            $table->unique('user_id');
        });

        // 2. Index composites pour l'optimisation des requêtes fréquentes du fil Square
        Schema::table('posts', function (Blueprint $table) {
            $table->index(['is_archived', 'audience', 'created_at'], 'posts_feed_discover_idx');
            $table->index(['place_city', 'is_archived', 'created_at'], 'posts_feed_city_idx');
            $table->index(['place_neighbourhood', 'is_archived', 'created_at'], 'posts_feed_neighbourhood_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_feed_neighbourhood_idx');
            $table->dropIndex('posts_feed_city_idx');
            $table->dropIndex('posts_feed_discover_idx');
        });

        Schema::table('housing_searches', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });
    }
};
