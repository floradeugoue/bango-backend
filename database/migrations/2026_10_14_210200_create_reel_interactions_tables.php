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
        // 1. Réactions sur les Reels (Likes / Dislikes avec exclusivité stricte au niveau base de données)
        Schema::create('reel_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // 'like' | 'dislike'
            $table->timestamps();

            // Règle d'exclusivité : un utilisateur ne peut avoir qu'une seule réaction active (like OU dislike) par reel
            $table->unique(['reel_id', 'user_id']);
            $table->index(['reel_id', 'type']);
        });

        // 2. Commentaires sur les Reels
        Schema::create('reel_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('reel_comments')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('likes_count')->default(0);
            $table->unsignedInteger('dislikes_count')->default(0);
            $table->unsignedInteger('replies_count')->default(0);
            $table->boolean('is_edited')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['reel_id', 'parent_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        // 3. Réactions sur les commentaires de Reels (Likes / Dislikes)
        Schema::create('reel_comment_reactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('reel_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type'); // 'like' | 'dislike'
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
            $table->index(['comment_id', 'type']);
        });

        // 4. Partages de Reels
        Schema::create('reel_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('platform')->nullable(); // native, whatsapp, copy_link, etc.
            $table->string('client_mutation_id')->nullable()->index(); // Idempotency
            $table->timestamps();

            $table->index(['reel_id', 'created_at']);
        });

        // 5. Vues de Reels avec protection anti-abus
        Schema::create('reel_views', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('viewed_at');

            $table->index(['reel_id', 'user_id', 'viewed_at']);
            $table->index(['reel_id', 'ip_address', 'viewed_at']);
        });

        // 6. Reels enregistrés / sauvegardés
        Schema::create('user_saved_reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'reel_id']);
        });

        // 7. Reels "Ça ne m'intéresse pas"
        Schema::create('user_not_interested_reels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('reel_id')->constrained('reels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'reel_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_not_interested_reels');
        Schema::dropIfExists('user_saved_reels');
        Schema::dropIfExists('reel_views');
        Schema::dropIfExists('reel_shares');
        Schema::dropIfExists('reel_comment_reactions');
        Schema::dropIfExists('reel_comments');
        Schema::dropIfExists('reel_reactions');
    }
};
