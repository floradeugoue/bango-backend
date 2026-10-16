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
        Schema::create('delegations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('bailleur_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();

            $table->string('status', 32)->default('pending')->index(); // pending, active, declined, revoked, expired
            $table->json('capabilities')->nullable(); // ["visits", "reply", "edit"]

            $table->dateTime('invited_at')->index();
            $table->dateTime('answered_at')->nullable();
            $table->dateTime('expires_at')->nullable()->index();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('revoked_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['bailleur_id', 'status']);
            $table->index(['agent_id', 'status']);
            $table->index(['listing_id', 'status']);
            $table->index(['listing_id', 'agent_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delegations');
    }
};
