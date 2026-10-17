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
        // Challenges 2FA temporaires pour les opérateurs
        Schema::create('admin_challenges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->string('type', 32)->default('totp'); // totp, sms, recovery_code
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'expires_at']);
        });

        // Sessions administratives avec cookies HttpOnly & rotation CSRF
        Schema::create('admin_sessions', function (Blueprint $table) {
            $table->string('id', 64)->primary(); // Valeur du cookie bango_admin_session
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('csrf_token', 64); // Valeur du cookie bango_admin_csrf
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('two_factor_verified_at');
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at'); // Expiration maximale absolue
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'last_activity_at']);
            $table->index(['expires_at', 'revoked_at']);
        });

        // Demandes d'accès interne (@bango.app)
        Schema::create('admin_access_requests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 191)->index();
            $table->string('department', 100);
            $table->string('requested_role', 50);
            $table->text('reason');
            $table->string('status', 30)->default('pending'); // pending, approved, rejected
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        // Invitations des collaborateurs internes
        Schema::create('admin_invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email', 191)->index();
            $table->string('role', 50);
            $table->string('token', 64)->unique();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->index(['token', 'expires_at', 'accepted_at']);
        });

        // Appareils de confiance & signalements d'appareils suspects
        Schema::create('admin_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('device_hash', 64)->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_trusted')->default(false);
            $table->string('verification_code', 16)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
            $table->timestamp('reported_suspicious_at')->nullable();
            $table->text('suspicion_reason')->nullable();
            $table->timestamp('last_seen_at');
            $table->timestamps();

            $table->unique(['user_id', 'device_hash']);
        });

        // Journalisation de sécurité & audits
        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event', 100)->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
        });

        // File des validations au principe des 4 yeux
        Schema::create('admin_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('validator_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 100)->index();
            $table->string('approvable_type')->nullable();
            $table->string('approvable_id')->nullable();
            $table->json('payload')->nullable();
            $table->string('status', 30)->default('pending')->index(); // pending, approved, rejected, cancelled
            $table->text('decision_reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id']);
            $table->index(['requester_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_approvals');
        Schema::dropIfExists('admin_audit_logs');
        Schema::dropIfExists('admin_devices');
        Schema::dropIfExists('admin_invitations');
        Schema::dropIfExists('admin_access_requests');
        Schema::dropIfExists('admin_sessions');
        Schema::dropIfExists('admin_challenges');
    }
};
