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
        // 1. Stories au nom de la Page
        if (Schema::hasTable('stories') && ! Schema::hasColumn('stories', 'page_id')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['page_id', 'status']);
            });
        }

        // 2. Likes de publications Square
        if (Schema::hasTable('post_likes') && ! Schema::hasColumn('post_likes', 'page_id')) {
            Schema::table('post_likes', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['post_id', 'page_id']);
            });
        }

        // 3. Commentaires Square
        if (Schema::hasTable('post_comments') && ! Schema::hasColumn('post_comments', 'page_id')) {
            Schema::table('post_comments', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['post_id', 'page_id']);
            });
        }

        // 4. Partages Square
        if (Schema::hasTable('post_shares') && ! Schema::hasColumn('post_shares', 'page_id')) {
            Schema::table('post_shares', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['post_id', 'page_id']);
            });
        }

        // 5. Réactions aux Reels
        if (Schema::hasTable('reel_reactions') && ! Schema::hasColumn('reel_reactions', 'page_id')) {
            Schema::table('reel_reactions', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['reel_id', 'page_id']);
            });
        }

        // 6. Commentaires de Reels
        if (Schema::hasTable('reel_comments') && ! Schema::hasColumn('reel_comments', 'page_id')) {
            Schema::table('reel_comments', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['reel_id', 'page_id']);
            });
        }

        // 7. Partages de Reels
        if (Schema::hasTable('reel_shares') && ! Schema::hasColumn('reel_shares', 'page_id')) {
            Schema::table('reel_shares', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['reel_id', 'page_id']);
            });
        }

        // 8. Reels sauvegardés par la Page
        if (Schema::hasTable('user_saved_reels') && ! Schema::hasColumn('user_saved_reels', 'page_id')) {
            Schema::table('user_saved_reels', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('user_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['page_id', 'reel_id']);
            });
        }

        // 9. Follows émis par la Page
        if (Schema::hasTable('follows') && ! Schema::hasColumn('follows', 'follower_page_id')) {
            Schema::table('follows', function (Blueprint $table) {
                $table->foreignId('follower_page_id')->nullable()->after('follower_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['follower_page_id', 'following_id']);
            });
        }

        // 10. Messages émis par la Page
        if (Schema::hasTable('messages') && ! Schema::hasColumn('messages', 'page_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->foreignId('page_id')->nullable()->after('sender_id')->constrained('pages')->cascadeOnDelete();
                $table->index(['conversation_id', 'page_id']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('messages') && Schema::hasColumn('messages', 'page_id')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('follows') && Schema::hasColumn('follows', 'follower_page_id')) {
            Schema::table('follows', function (Blueprint $table) {
                $table->dropForeign(['follower_page_id']);
                $table->dropColumn('follower_page_id');
            });
        }

        if (Schema::hasTable('user_saved_reels') && Schema::hasColumn('user_saved_reels', 'page_id')) {
            Schema::table('user_saved_reels', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('reel_shares') && Schema::hasColumn('reel_shares', 'page_id')) {
            Schema::table('reel_shares', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('reel_comments') && Schema::hasColumn('reel_comments', 'page_id')) {
            Schema::table('reel_comments', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('reel_reactions') && Schema::hasColumn('reel_reactions', 'page_id')) {
            Schema::table('reel_reactions', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('post_shares') && Schema::hasColumn('post_shares', 'page_id')) {
            Schema::table('post_shares', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('post_comments') && Schema::hasColumn('post_comments', 'page_id')) {
            Schema::table('post_comments', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('post_likes') && Schema::hasColumn('post_likes', 'page_id')) {
            Schema::table('post_likes', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }

        if (Schema::hasTable('stories') && Schema::hasColumn('stories', 'page_id')) {
            Schema::table('stories', function (Blueprint $table) {
                $table->dropForeign(['page_id']);
                $table->dropColumn('page_id');
            });
        }
    }
};
