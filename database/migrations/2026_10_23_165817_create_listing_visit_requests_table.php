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
        Schema::create('listing_visit_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('prospect_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();

            // Proposals array [ISO 8601 x 1..3]
            $table->json('proposals');
            $table->text('message')->nullable();

            // Status: pending, chosen, cancelled, expired, declined
            $table->string('status', 32)->default('pending')->index();

            $table->unsignedTinyInteger('chosen_proposal_index')->nullable();
            $table->dateTime('chosen_slot_at')->nullable();
            $table->foreignUuid('visit_reservation_id')->nullable()->constrained('visit_reservations')->nullOnDelete();

            $table->dateTime('viewed_by_landlord_at')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('cancelled_at')->nullable();

            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->index(['owner_id', 'status']);
            $table->index(['prospect_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_visit_requests');
    }
};
