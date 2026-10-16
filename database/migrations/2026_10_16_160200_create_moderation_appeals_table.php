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
        Schema::create('moderation_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('sanction_type'); // 'account_suspension', 'page_suspension', 'content_removal', 'warning'
            $table->string('sanction_reference')->nullable();
            $table->string('reason');
            $table->text('explanation');
            $table->string('contact_email')->nullable();
            $table->string('status')->default('pending'); // 'pending', 'under_review', 'approved', 'rejected'
            $table->text('decision_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['sanction_type', 'sanction_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('moderation_appeals');
    }
};
