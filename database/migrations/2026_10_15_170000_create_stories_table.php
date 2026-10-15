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
        Schema::create('stories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('audience', 32)->default('everyone'); // everyone, followers, chosen
            $table->boolean('allow_replies')->default(true);
            $table->boolean('allow_archive')->default(true);
            $table->string('status', 32)->default('active'); // active, expired, archived, deleted

            $table->timestamp('published_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('poll_votes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);

            $table->json('metadata')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
            $table->index(['status', 'published_at']);
            $table->index('created_at');
        });

        Schema::create('story_slides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();

            $table->string('media_type', 32)->default('image'); // image, video
            $table->text('media_path');
            $table->text('media_url')->nullable();
            $table->text('thumbnail_path')->nullable();
            $table->text('thumbnail_url')->nullable();

            $table->string('filter', 64)->nullable();
            $table->text('caption')->nullable();
            $table->unsignedInteger('duration')->nullable(); // en secondes (ex: 5s photo, max 30s vidéo)
            $table->unsignedInteger('order')->default(0);

            $table->string('sound_id')->nullable();
            $table->foreign('sound_id')->references('id')->on('sounds')->nullOnDelete();
            $table->json('sound_metadata')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index(['story_id', 'order']);
        });

        Schema::create('story_stickers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_slide_id')->constrained('story_slides')->cascadeOnDelete();

            $table->string('type', 64); // text, poll, question, location, mention, countdown, housing, music
            $table->float('x')->default(0.5); // coordonnées relatives [0..1]
            $table->float('y')->default(0.5);
            $table->float('scale')->default(1.0);
            $table->float('rotation')->default(0.0);
            $table->json('data');

            $table->timestamps();

            $table->index('story_slide_id');
            $table->index(['story_slide_id', 'type']);
        });

        Schema::create('story_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('story_slide_id')->constrained('story_slides')->cascadeOnDelete();
            $table->foreignUuid('sticker_id')->constrained('story_stickers')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedInteger('option_index'); // 0 ou 1 pour sondage binaire, ou index de l'option choisie
            $table->timestamps();

            $table->unique(['sticker_id', 'user_id']); // Double vote strictement interdit
            $table->index(['story_slide_id', 'sticker_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_poll_votes');
        Schema::dropIfExists('story_stickers');
        Schema::dropIfExists('story_slides');
        Schema::dropIfExists('stories');
    }
};
