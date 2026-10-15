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
        Schema::create('story_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignUuid('story_slide_id')->nullable()->constrained('story_slides')->nullOnDelete();
            $table->foreignId('viewer_id')->constrained('users')->cascadeOnDelete();

            $table->string('ip_address', 64)->nullable(); // Hachage anonymisé pour déduplication anti-abus
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['story_id', 'viewer_id']);
            $table->index(['story_id', 'viewed_at']);
        });

        Schema::create('story_replies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->uuid('story_slide_id')->nullable();
            $table->foreignId('story_author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // Répondant

            $table->string('type', 32)->default('text'); // text, reaction
            $table->text('text')->nullable();
            $table->string('reaction', 16)->nullable(); // Emoji
            $table->string('status', 32)->default('pending'); // pending, forwarded_to_inbox, dismissed
            $table->string('idempotency_key', 128)->nullable();

            $table->json('metadata')->nullable(); // Snapshot pour carte d'aperçu Story dans la future messagerie
            $table->string('future_conversation_id', 128)->nullable();
            $table->string('future_message_id', 128)->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'idempotency_key']);
            $table->index(['story_id', 'user_id']);
            $table->index(['story_author_id', 'status']);
            $table->index('created_at');
        });

        Schema::create('user_close_friends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('friend_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'friend_id']);
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_close_friends');
        Schema::dropIfExists('story_replies');
        Schema::dropIfExists('story_views');
    }
};
