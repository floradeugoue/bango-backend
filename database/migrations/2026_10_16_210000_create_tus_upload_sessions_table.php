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
        Schema::create('tus_upload_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('upload_length');
            $table->unsignedBigInteger('upload_offset')->default(0);
            $table->string('mime_type')->default('video/mp4');
            $table->string('original_filename')->nullable();
            $table->json('metadata')->nullable();
            $table->string('status', 32)->default('active'); // active, completed, cancelled, expired
            $table->string('temp_path');
            $table->string('final_path')->nullable();
            $table->string('final_url')->nullable();
            $table->string('disk', 32)->default('local');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tus_upload_sessions');
    }
};
