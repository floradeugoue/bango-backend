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
        Schema::table('posts', function (Blueprint $table) {
            $table->unsignedInteger('saves_count')->default(0)->after('shares_count');
        });

        Schema::table('reels', function (Blueprint $table) {
            $table->unsignedInteger('saves_count')->default(0)->after('shares_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            $table->dropColumn('saves_count');
        });

        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('saves_count');
        });
    }
};
