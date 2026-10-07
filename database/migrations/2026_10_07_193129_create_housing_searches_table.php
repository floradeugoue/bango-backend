<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('housing_searches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('intent')->nullable();
            $table->json('types')->nullable();
            $table->json('budget')->nullable();
            $table->string('currency')->nullable();
            $table->string('city')->nullable();
            $table->json('neighbourhoods')->nullable();
            $table->string('place')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('housing_searches');
    }
};
