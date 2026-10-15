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
        Schema::create('sounds', function (Blueprint $table) {
            $table->string('id')->primary(); // ex: 'afro-chill', 'makossa-sun', 'orig:...'
            $table->string('title');
            $table->string('artist');
            $table->string('genre')->default('afro'); // afro, makossa, coupe-decale, gospel, ambiance, highlife, amapiano, traditional
            $table->string('audio_url')->nullable();
            $table->integer('duration_seconds')->default(30);
            $table->integer('base_uses')->default(0);
            $table->integer('uses_count')->default(0);
            $table->boolean('is_original')->default(false);
            $table->string('original_reel_id')->nullable()->index();
            $table->boolean('is_locked')->default(false);
            $table->boolean('is_removed')->default(false);
            $table->jsonb('territories')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['genre', 'uses_count']);
            $table->index('is_original');
        });

        Schema::create('user_favourite_sounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sound_id');
            $table->foreign('sound_id')->references('id')->on('sounds')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'sound_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_favourite_sounds');
        Schema::dropIfExists('sounds');
    }
};
