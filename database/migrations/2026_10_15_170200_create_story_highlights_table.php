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
        Schema::create('story_highlights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('title', 20); // Strictement limité à 20 caractères selon la règle métier
            $table->foreignUuid('cover_story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->text('cover_media_url')->nullable();
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();

            $table->index(['user_id', 'order']);
        });

        Schema::create('story_highlight_items', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('highlight_id')->constrained('story_highlights')->cascadeOnDelete();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->unsignedInteger('order')->default(0);

            $table->timestamps();

            $table->unique(['highlight_id', 'story_id']);
            $table->index(['highlight_id', 'order']);
        });

        Schema::create('story_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();

            $table->string('reason', 64);
            $table->text('details')->nullable();
            $table->string('status', 32)->default('pending'); // pending, reviewed, dismissed, actioned

            $table->timestamps();

            $table->index(['story_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('story_reports');
        Schema::dropIfExists('story_highlight_items');
        Schema::dropIfExists('story_highlights');
    }
};
