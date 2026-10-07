<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interest_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('interest_id');
            $table->foreign('interest_id')->references('id')->on('interests')->cascadeOnDelete();
            $table->unique(['user_id', 'interest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('interest_user');
    }
};
