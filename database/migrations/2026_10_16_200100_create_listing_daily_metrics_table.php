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
        Schema::create('listing_daily_metrics', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->date('metric_date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('video_views')->default(0);
            $table->unsignedInteger('favorites')->default(0);
            $table->unsignedInteger('contacts')->default(0);
            $table->timestamps();

            $table->unique(['listing_id', 'metric_date']);
            $table->index(['listing_id', 'metric_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_daily_metrics');
    }
};
