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
        // 1. Stories : story_views, story_likes, story_replies, story_poll_votes, story_sticker_answers
        Schema::table('story_views', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('viewer_id')->constrained('pages')->nullOnDelete();
        });

        Schema::table('story_likes', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
        });

        Schema::table('story_replies', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
        });

        Schema::table('story_poll_votes', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
        });

        Schema::table('story_sticker_answers', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->nullOnDelete();
        });

        // 2. Réactions commentaires Square & Reels : page_id + unicité (comment_id, user_id, page_id)
        Schema::table('post_comment_reactions', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
            $table->dropUnique(['comment_id', 'user_id']);
            $table->unique(['comment_id', 'user_id', 'page_id']);
        });

        Schema::table('reel_comment_reactions', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
            $table->dropUnique(['comment_id', 'user_id']);
            $table->unique(['comment_id', 'user_id', 'page_id']);
        });

        // 3. À la une (story_highlights) : page_id
        Schema::table('story_highlights', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
            $table->index(['page_id', 'order']);
        });

        // 4. Blocages (user_blocks) : page_id
        Schema::table('user_blocks', function (Blueprint $table) {
            $table->foreignId('page_id')->nullable()->after('blocker_id')->constrained('pages')->cascadeOnDelete();
            $table->dropUnique(['blocker_id', 'blocked_id']);
            $table->unique(['blocker_id', 'blocked_id', 'page_id']);
            $table->index(['page_id', 'blocked_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('user_blocks', function (Blueprint $table) {
            $table->dropUnique(['blocker_id', 'blocked_id', 'page_id']);
            $table->unique(['blocker_id', 'blocked_id']);
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_highlights', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('reel_comment_reactions', function (Blueprint $table) {
            $table->dropUnique(['comment_id', 'user_id', 'page_id']);
            $table->unique(['comment_id', 'user_id']);
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('post_comment_reactions', function (Blueprint $table) {
            $table->dropUnique(['comment_id', 'user_id', 'page_id']);
            $table->unique(['comment_id', 'user_id']);
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_sticker_answers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_poll_votes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_replies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_likes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });

        Schema::table('story_views', function (Blueprint $table) {
            $table->dropConstrainedForeignId('page_id');
        });
    }
};
