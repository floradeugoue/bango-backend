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
        Schema::create('agent_payouts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('landlord_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('visit_reservation_id')->nullable()->constrained('visit_reservations')->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();

            $table->decimal('amount', 12, 2);
            $table->decimal('percent', 5, 2)->default(0);
            $table->string('currency', 10)->default('XAF');
            $table->string('status')->default('scheduled')->index(); // scheduled, processing, completed, failed, cancelled

            $table->timestamp('scheduled_at')->index();
            $table->timestamp('executed_at')->nullable();

            $table->string('provider')->default('monetbil');
            $table->string('channel')->default('mobile_money');
            $table->string('external_reference')->nullable()->index();

            $table->unsignedInteger('attempts_count')->default(0);
            $table->text('last_error')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['agent_id', 'status']);
            $table->index(['status', 'scheduled_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_payouts');
    }
};
