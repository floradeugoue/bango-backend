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
        Schema::table('listing_daily_metrics', function (Blueprint $table) {
            $table->unsignedInteger('views_feed')->default(0)->after('views');
            $table->unsignedInteger('views_reel')->default(0)->after('views_feed');
            $table->unsignedInteger('views_search')->default(0)->after('views_reel');
            $table->unsignedInteger('views_link')->default(0)->after('views_search');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_daily_metrics', function (Blueprint $table) {
            $table->dropColumn(['views_feed', 'views_reel', 'views_search', 'views_link']);
        });
    }
};
