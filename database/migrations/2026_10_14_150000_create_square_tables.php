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
        // 1. Publications Square (Posts)
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('page_id')->nullable()->index();
            $table->text('body')->nullable();
            $table->string('audience')->default('public')->index(); // public, followers, neighbourhood
            $table->string('comments_by')->default('everyone'); // everyone, followers, nobody
            $table->boolean('comments_closed')->default(false);
            $table->boolean('allow_share')->default(true);
            $table->boolean('hide_likes')->default(false);
            $table->boolean('is_edited')->default(false);
            $table->boolean('is_archived')->default(false)->index();
            $table->string('place_label')->nullable();
            $table->string('place_detail')->nullable();
            $table->string('place_city')->nullable()->index();
            $table->string('place_neighbourhood')->nullable()->index();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('comments_count')->default(0);
            $table->unsignedInteger('shares_count')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['user_id', 'is_archived']);
            $table->index(['created_at', 'is_archived']);
        });

        // 2. Médias rattachés aux publications (Carrousel de 1 à 10 éléments)
        Schema::create('post_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->string('media_url');
            $table->string('media_type')->default('photo'); // photo, video
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedInteger('duration')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->json('edit_metadata')->nullable();
            $table->timestamps();

            $table->index(['post_id', 'display_order']);
        });

        // 3. Identification de personnes sur les médias photos (Tags)
        Schema::create('post_tags', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_media_id')->constrained('post_media')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('x', 5, 4); // Coordonnée normalisée relative 0.0000 .. 1.0000
            $table->decimal('y', 5, 4); // Coordonnée normalisée relative 0.0000 .. 1.0000
            $table->timestamps();

            $table->unique(['post_media_id', 'user_id']);
            $table->index('user_id');
        });

        // 4. Hashtags
        Schema::create('hashtags', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('normalized_name')->unique()->index();
            $table->unsignedInteger('posts_count')->default(0)->index();
            $table->timestamps();
        });

        // 5. Pivot Posts - Hashtags
        Schema::create('post_hashtag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts')->cascadeOnDelete();
            $table->foreignId('hashtag_id')->constrained('hashtags')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['post_id', 'hashtag_id']);
            $table->index('hashtag_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('post_hashtag');
        Schema::dropIfExists('hashtags');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_media');
        Schema::dropIfExists('posts');
    }
};
