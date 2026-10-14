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
        Schema::create('payment_disputes', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32)->default('dispute'); // dispute, double_charge
            $table->string('status', 32)->default('opened'); // opened, under_review, resolved, rejected, closed
            $table->text('reason');
            $table->string('claimant_phone', 32)->nullable();
            $table->string('operator_reference', 128)->nullable();
            $table->decimal('disputed_amount', 12, 2)->default(0.00);
            $table->string('currency', 3)->default('XAF');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_decision')->nullable();
            $table->json('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            // Index pour les filtres Back-Office fréquents
            $table->index(['type', 'status']);
            $table->index('user_id');
            $table->index('transaction_id');
            $table->index('operator_reference');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_disputes');
    }
};
