<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_search_neighbourhood', function (Blueprint $table) {
            $table->id();
            $table->foreignId('housing_search_id')->constrained('housing_searches')->cascadeOnDelete();
            $table->foreignId('neighbourhood_id')->constrained('neighbourhoods')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housing_search_neighbourhood');
    }
};
