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
        Schema::table('listing_alerts', function (Blueprint $table) {
            $table->string('paused_reason', 64)->nullable()->after('is_active');
            $table->timestamp('last_matched_at')->nullable()->after('last_triggered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_alerts', function (Blueprint $table) {
            $table->dropColumn(['paused_reason', 'last_matched_at']);
        });
    }
};
