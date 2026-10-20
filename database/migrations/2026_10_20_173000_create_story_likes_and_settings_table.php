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
        Schema::table('stories', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0)->after('replies_count');
        });

        Schema::create('story_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['story_id', 'user_id']);
            $table->index(['story_id', 'created_at']);
        });

        Schema::create('story_sticker_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignUuid('slide_id')->constrained('story_slides')->cascadeOnDelete();
            $table->foreignUuid('sticker_id')->constrained('story_stickers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32); // question, countdown, etc.
            $table->text('answer')->nullable();
            $table->timestamps();

            $table->index(['sticker_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_sticker_answers');
        Schema::dropIfExists('story_likes');
        Schema::table('stories', function (Blueprint $table) {
            $table->dropColumn('likes_count');
        });
    }
};
