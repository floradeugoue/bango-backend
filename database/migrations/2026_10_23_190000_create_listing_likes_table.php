<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->unsignedInteger('likes_count')->default(0)->after('saves_count');
        });

        Schema::create('listing_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'listing_id']);
            $table->index(['user_id', 'created_at']);
            $table->index(['listing_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_likes');

        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('likes_count');
        });
    }
};
