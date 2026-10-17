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
        Schema::create('kyc_document_access_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('kyc_submission_id')->constrained('kyc_submissions')->cascadeOnDelete();
            $table->foreignUuid('kyc_document_id')->constrained('kyc_documents')->cascadeOnDelete();
            $table->string('action', 32)->default('open');
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['operator_id', 'created_at']);
            $table->index(['kyc_submission_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_document_access_logs');
    }
};
