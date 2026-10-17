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
        Schema::create('moderation_filter_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('content_type');
            $table->string('content_id');
            $table->string('category'); // 'scam', 'phone_leak', 'off_platform', 'offensive'
            $table->string('detected_pattern');
            $table->text('original_text');
            $table->string('action_taken')->default('flagged'); // 'flagged', 'blocked', 'dismissed'
            $table->timestamp('created_at')->useCurrent();

            $table->index(['content_type', 'content_id']);
            $table->index('category');
            $table->index('action_taken');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_filter_logs');
    }
};
