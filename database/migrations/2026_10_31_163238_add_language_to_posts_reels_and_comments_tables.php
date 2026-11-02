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
            $table->string('language', 10)->nullable()->default('fr')->after('body');
        });

        Schema::table('reels', function (Blueprint $table) {
            $table->string('language', 10)->nullable()->default('fr')->after('caption');
        });

        Schema::table('post_comments', function (Blueprint $table) {
            $table->string('language', 10)->nullable()->default('fr')->after('body');
        });

        Schema::table('reel_comments', function (Blueprint $table) {
            $table->string('language', 10)->nullable()->default('fr')->after('body');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('reels', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('post_comments', function (Blueprint $table) {
            $table->dropColumn('language');
        });

        Schema::table('reel_comments', function (Blueprint $table) {
            $table->dropColumn('language');
        });
    }
};
