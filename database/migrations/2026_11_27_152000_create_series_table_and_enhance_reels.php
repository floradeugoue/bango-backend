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
        Schema::create('series', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique()->index();
            $table->text('description')->nullable();
            $table->string('cover_url')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('pages')->nullOnDelete();
            $table->unsignedInteger('episodes_count')->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::table('reels', function (Blueprint $table) {
            if (! Schema::hasColumn('reels', 'series_id')) {
                $table->foreignId('series_id')->nullable()->after('listing_id')->constrained('series')->nullOnDelete();
            }
            if (! Schema::hasColumn('reels', 'episode_number')) {
                $table->unsignedInteger('episode_number')->nullable()->after('series_id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reels', function (Blueprint $table) {
            if (Schema::hasColumn('reels', 'episode_number')) {
                $table->dropColumn('episode_number');
            }
            if (Schema::hasColumn('reels', 'series_id')) {
                $table->dropForeign(['series_id']);
                $table->dropColumn('series_id');
            }
        });

        Schema::dropIfExists('series');
    }
};
