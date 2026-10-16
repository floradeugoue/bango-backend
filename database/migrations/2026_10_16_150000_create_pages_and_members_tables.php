<?php

use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageSector;
use App\Enums\Page\PageStatus;
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
        // 1. Table principale des Pages Professionnelles
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->string('handle')->unique();
            $table->text('description')->nullable();
            $table->string('sector', 64)->default(PageSector::Other->value)->index();
            $table->string('logo_url')->nullable();
            $table->string('cover_url')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('website')->nullable();
            $table->string('address')->nullable();
            $table->string('city')->nullable()->index();
            $table->string('neighbourhood')->nullable()->index();
            $table->string('certification_status', 32)->default(PageCertificationStatus::None->value)->index();
            $table->string('status', 32)->default(PageStatus::Active->value)->index();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'certification_status']);
            $table->index(['sector', 'status']);
            $table->index(['city', 'neighbourhood']);
        });

        // 2. Table pivot des membres et rôles RBAC
        Schema::create('page_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role', 32)->default(PageRole::Editor->value);
            $table->string('status', 32)->default(PageMemberStatus::Pending->value)->index();
            $table->foreignId('invited_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['page_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['page_id', 'role', 'status']);
        });

        // 3. Table de suivi des dossiers de certification entreprise
        Schema::create('page_certifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('page_id')->constrained('pages')->cascadeOnDelete();
            $table->foreignId('submitted_by_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 32)->default(PageCertificationStatus::Pending->value)->index();
            $table->string('rccm_number', 128)->nullable();
            $table->string('nif_number', 128)->nullable();
            $table->string('legal_representative_name')->nullable();
            $table->string('legal_representative_id_type', 32)->nullable();
            $table->string('legal_representative_id_number', 64)->nullable();
            $table->json('documents')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('supplement_request_reason')->nullable();
            $table->unsignedInteger('corrections_count')->default(0);
            $table->foreignId('payment_transaction_id')->nullable()->constrained('payment_transactions')->nullOnDelete();
            $table->foreignId('decided_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->json('history')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['page_id', 'status']);
            $table->index(['status', 'submitted_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('page_certifications');
        Schema::dropIfExists('page_members');
        Schema::dropIfExists('pages');
    }
};
