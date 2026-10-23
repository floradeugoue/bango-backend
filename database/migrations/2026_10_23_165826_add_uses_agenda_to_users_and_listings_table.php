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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('uses_agenda')->default(true)->after('verification_lapsed');
        });

        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('uses_agenda')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('uses_agenda');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('uses_agenda');
        });
    }
};
