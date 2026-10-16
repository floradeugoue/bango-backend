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
        // 1. Table des conversations (fils de discussion)
        Schema::create('conversations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 32)->default('direct')->index(); // direct, group, page
            $table->string('title')->nullable(); // Nom du groupe ou sujet
            $table->string('avatar_url')->nullable(); // Avatar pour les groupes
            $table->foreignUuid('listing_id')->nullable()->constrained('listings')->nullOnDelete();
            $table->foreignId('created_by_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->json('metadata')->nullable(); // Métadonnées contextuelles (sujet, etc.)
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'created_by_id']);
            $table->index(['listing_id', 'type']);
        });

        // 2. Table des membres d'une conversation
        Schema::create('conversation_members', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default('member'); // member, admin
            $table->boolean('is_muted')->default(false);
            $table->uuid('last_read_message_id')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('left_at')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id', 'role']);
            $table->index(['conversation_id', 'role']);
        });

        // 3. Table des messages
        Schema::create('messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 32)->default('text')->index(); // text, voice, image, listing, story_reply, system
            $table->text('body')->nullable();
            $table->string('client_message_id', 128)->nullable()->index();
            $table->uuid('reply_to_message_id')->nullable()->index();

            // Pièces jointes (photos, notes vocales)
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 64)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->unsignedInteger('attachment_duration_ms')->nullable(); // Pour les notes vocales (MS-03)

            // Métadonnées (story snapshot, aperçu annonce partagée, etc.)
            $table->json('metadata')->nullable();

            // Retrait de message (retracted)
            $table->boolean('is_retracted')->default(false);
            $table->timestamp('retracted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['conversation_id', 'client_message_id']);
        });

        Schema::table('messages', function (Blueprint $table) {
            $table->foreign('reply_to_message_id')->references('id')->on('messages')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversation_members');
        Schema::dropIfExists('conversations');
    }
};
