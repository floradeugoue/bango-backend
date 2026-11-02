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
        if (Schema::hasTable('users') && ! Schema::hasColumn('users', 'payout_method')) {
            Schema::table('users', function (Blueprint $table) {
                $table->json('payout_method')->nullable()->after('privacy_settings');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'payout_method')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('payout_method');
            });
        }
    }
};
