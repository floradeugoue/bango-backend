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
        if (Schema::hasTable('posts') && ! Schema::hasColumn('posts', 'ai_generated')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->boolean('ai_generated')->default(false)->after('body');
            });
        }

        if (Schema::hasTable('reels') && ! Schema::hasColumn('reels', 'ai_generated')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->boolean('ai_generated')->default(false)->after('caption');
            });
        }

        if (Schema::hasTable('stories') && ! Schema::hasColumn('stories', 'ai_generated')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->boolean('ai_generated')->default(false);
            });
        }

        if (Schema::hasTable('story_slides') && ! Schema::hasColumn('story_slides', 'ai_generated')) {
            Schema::table('story_slides', function (Blueprint $table) {
                $table->boolean('ai_generated')->default(false);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('posts') && Schema::hasColumn('posts', 'ai_generated')) {
            Schema::table('posts', function (Blueprint $table) {
                $table->dropColumn('ai_generated');
            });
        }

        if (Schema::hasTable('reels') && Schema::hasColumn('reels', 'ai_generated')) {
            Schema::table('reels', function (Blueprint $table) {
                $table->dropColumn('ai_generated');
            });
        }

        if (Schema::hasTable('stories') && Schema::hasColumn('stories', 'ai_generated')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropColumn('ai_generated');
            });
        }

        if (Schema::hasTable('story_slides') && Schema::hasColumn('story_slides', 'ai_generated')) {
            Schema::table('story_slides', function (Blueprint $table) {
                $table->dropColumn('ai_generated');
            });
        }
    }
};
