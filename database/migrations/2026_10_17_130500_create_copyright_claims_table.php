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
        Schema::create('copyright_claims', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('claimant_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('claimant_name');
            $table->string('claimant_email');
            $table->string('content_type'); // 'post', 'reel', 'sound', etc.
            $table->string('content_id');
            $table->string('work_title');
            $table->string('proof_url')->nullable();
            $table->text('reason');
            $table->string('status')->default('pending'); // 'pending', 'under_review', 'approved', 'rejected'
            $table->string('decision')->nullable(); // 'content_removed', 'sound_muted', 'dismissed'
            $table->text('decision_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index('reference');
            $table->index('status');
            $table->index(['content_type', 'content_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('copyright_claims');
    }
};
