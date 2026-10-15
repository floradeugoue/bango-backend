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
        Schema::create('reels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sound_id')->nullable();
            $table->foreign('sound_id')->references('id')->on('sounds')->nullOnDelete();

            $table->text('caption')->nullable();
            $table->string('topic')->default('neighbourhood');
            $table->string('status')->default('ready'); // draft, processing, ready, failed
            $table->text('error_message')->nullable();

            $table->text('video_url')->nullable();
            $table->text('thumbnail_url')->nullable();
            $table->text('hls_url')->nullable();
            $table->string('stream_media_id')->nullable()->index(); // Cloudflare Stream UID

            $table->integer('duration')->nullable(); // in seconds or milliseconds
            $table->integer('width')->nullable();
            $table->integer('height')->nullable();
            $table->string('aspect_ratio')->default('9:16');
            $table->boolean('allow_download')->default(true);
            $table->boolean('comments_closed')->default(false);
            $table->text('subtitles')->nullable();
            $table->string('subtitles_locale')->default('fr');

            $table->jsonb('composition')->nullable(); // ReelEdit structure (clips, overlays, volumes, filters, etc.)
            $table->jsonb('renditions')->nullable(); // 480p, 720p, 1080p URLs
            $table->string('listing_id')->nullable();

            $table->unsignedInteger('views_count')->default(0);
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('dislikes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'published_at']);
            $table->index('topic');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reels');
    }
};
