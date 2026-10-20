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
        Schema::table('admin_sessions', function (Blueprint $table) {
            $table->json('allowed_countries')->nullable()->after('user_agent');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->json('allowed_countries')->nullable()->after('country_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admin_sessions', function (Blueprint $table) {
            $table->dropColumn('allowed_countries');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('allowed_countries');
        });
    }
};
