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
        Schema::table('story_likes', function (Blueprint $table) {
            $table->foreignUuid('slide_id')->nullable()->after('story_id')->constrained('story_slides')->cascadeOnDelete();

            // Remplacer l'unicité stricte (story_id, user_id) par (story_id, slide_id, user_id)
            $table->dropUnique('story_likes_story_id_user_id_unique');
            $table->unique(['story_id', 'slide_id', 'user_id'], 'story_likes_story_slide_user_unique');
            $table->index(['slide_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('story_likes', function (Blueprint $table) {
            $table->dropUnique('story_likes_story_slide_user_unique');
            $table->dropIndex(['slide_id', 'created_at']);
            $table->dropConstrainedForeignId('slide_id');
            $table->unique(['story_id', 'user_id'], 'story_likes_story_id_user_id_unique');
        });
    }
};
