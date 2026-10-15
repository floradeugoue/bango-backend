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
        // 1. Table des créneaux de visite
        Schema::create('visit_slots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->dateTime('start_at')->index();
            $table->dateTime('end_at')->index();
            $table->unsignedInteger('capacity')->default(1);
            $table->unsignedInteger('booked_count')->default(0);
            $table->string('status')->default('open')->index(); // open, closed, cancelled

            $table->timestamps();

            $table->unique(['listing_id', 'start_at', 'end_at'], 'uniq_slot_listing_interval');
            $table->index(['listing_id', 'status', 'start_at']);
            $table->index(['owner_id', 'start_at']);
            $table->index(['agent_id', 'start_at']);
        });

        // 2. Table des réservations de visite
        Schema::create('visit_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignUuid('slot_id')->constrained('visit_slots')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status')->default('pending_payment')->index();
            $table->dateTime('lock_expires_at')->nullable()->index();

            $table->decimal('fee', 12, 2)->default(0);
            $table->string('currency', 10)->default('XOF');
            $table->text('message')->nullable();

            $table->string('payment_reference')->nullable()->index();
            $table->foreignId('transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->dateTime('paid_at')->nullable();

            $table->dateTime('answered_at')->nullable();
            $table->foreignUuid('proposal_slot_id')->nullable()->constrained('visit_slots')->nullOnDelete();
            $table->string('decline_reason')->nullable();

            $table->dateTime('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();

            $table->dateTime('refunded_at')->nullable();
            $table->decimal('refund_amount', 12, 2)->nullable();
            $table->string('refund_channel')->nullable(); // bango_credit, mobile_money

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['prospect_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['slot_id', 'status']);
            $table->index(['lock_expires_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('visit_reservations');
        Schema::dropIfExists('visit_slots');
    }
};
