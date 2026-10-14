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
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('payment_transactions')->restrictOnDelete();
            $table->unsignedInteger('attempt_number');

            $table->string('provider', 32);
            $table->string('channel', 32)->nullable();
            $table->string('external_reference', 128)->nullable();
            $table->string('status', 32)->default('initiated');

            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();

            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();

            $table->timestamps();

            // Unicité par tentative pour une même transaction
            $table->unique(['transaction_id', 'attempt_number']);

            // Index
            $table->index(['transaction_id', 'status']);
            $table->index(['provider', 'external_reference']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
    }
};
