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
        Schema::create('payment_refund_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('transaction_id')->constrained('payment_transactions')->cascadeOnDelete();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending_approval')->index(); // pending_approval, approved, rejected, withdrawn
            $table->decimal('amount', 12, 2);
            $table->string('currency', 10)->default('XAF');
            $table->text('reason');
            $table->text('decision_reason')->nullable();

            $table->timestamp('decided_at')->nullable();
            $table->timestamp('withdrawn_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['transaction_id', 'status']);
            $table->index(['requester_id', 'status']);
            $table->index(['approver_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_refund_requests');
    }
};
