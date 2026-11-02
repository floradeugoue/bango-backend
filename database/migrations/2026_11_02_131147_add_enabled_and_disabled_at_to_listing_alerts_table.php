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
            $table->boolean('enabled')->default(true)->after('email');
            $table->timestamp('disabled_at')->nullable()->after('enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listing_alerts', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'disabled_at']);
        });
    }
};
