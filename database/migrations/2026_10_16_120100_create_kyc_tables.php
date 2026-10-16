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
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('status', 32)->default('pending')->index(); // pending, supplement_requested, approved, rejected
            $table->string('full_name')->nullable();
            $table->string('id_type', 32)->nullable(); // cni, passport, residence_permit
            $table->string('id_number', 64)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('property_right', 32)->nullable(); // owner, representative, deed
            $table->string('deed_type', 64)->nullable();

            $table->text('rejection_reason')->nullable();
            $table->dateTime('decided_at')->nullable();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });

        Schema::create('kyc_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('kyc_submission_id')->constrained('kyc_submissions')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('kind', 32); // front, back, selfie, deed, other
            $table->text('file_path');
            $table->string('original_name');
            $table->string('mime_type', 64);
            $table->unsignedBigInteger('file_size');
            $table->string('status', 32)->default('pending'); // pending, approved, rejected
            $table->string('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['user_id', 'kind']);
            $table->index(['kyc_submission_id', 'kind']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('kyc_submissions');
    }
};
