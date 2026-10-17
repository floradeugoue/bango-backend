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
        // 1. Rejet de faux positifs sur la détection des doublons
        Schema::create('listing_duplicate_dismissals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignUuid('dismissed_listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['listing_id', 'dismissed_listing_id']);
        });

        // 2. Traçabilité des fusions de doublons
        Schema::create('listing_merges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('primary_listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignUuid('secondary_listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['primary_listing_id', 'created_at']);
        });

        // 3. Certifications « visité sur place » par un agent ou opérateur
        Schema::create('listing_agent_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('certified_at');
            $table->text('notes')->nullable();
            $table->json('evidence')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'agent_id']);
        });

        // 4. Journal d'audit des revues et contrôles d'annonces (approve, reject, unpublish, republish, rented)
        Schema::create('listing_review_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('listing_id')->constrained('listings')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('action', 32); // approve, reject, request_changes, unpublish, republish, rented
            $table->string('from_status', 32)->nullable();
            $table->string('to_status', 32)->nullable();
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['listing_id', 'action']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listing_review_logs');
        Schema::dropIfExists('listing_agent_certifications');
        Schema::dropIfExists('listing_merges');
        Schema::dropIfExists('listing_duplicate_dismissals');
    }
};
