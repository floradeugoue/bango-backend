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
        // 1. Colonnes supplémentaires pour support_tickets (résolution, canal d'origine)
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->foreignId('resolved_by_id')->nullable()->after('assigned_to')->constrained('users')->nullOnDelete();
            $table->string('resolution_category', 64)->nullable()->after('status');
            $table->text('resolution_reason')->nullable()->after('resolution_category');
            $table->string('channel', 32)->default('in_app')->after('category');
        });

        // 2. Colonne de rétention pour support_ticket_messages (notes internes confidentielles 5 ans)
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->timestamp('retained_until')->nullable()->after('is_internal');
        });

        // 3. Colonnes de certification notable pour users (distinction avec KYC is_verified)
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_certified')->default(false)->after('is_verified');
            $table->timestamp('certified_at')->nullable()->after('is_certified');
        });

        // 4. Table des escalades pour avis d'équipes ou de superviseurs
        Schema::create('support_ticket_escalations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('escalated_by_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('escalated_to_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('target_team', 64)->default('general')->index(); // 'legal', 'finance', 'tech', 'moderation', 'supervisors'
            $table->text('reason');
            $table->string('status', 32)->default('pending')->index(); // 'pending', 'answered', 'closed'
            $table->text('response')->nullable();
            $table->foreignId('answered_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            $table->index(['support_ticket_id', 'status']);
        });

        // 5. Table des réponses types / modèles de réponse pour le support
        Schema::create('support_response_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->string('code', 64)->nullable()->unique();
            $table->string('category', 64)->default('general')->index();
            $table->text('content');
            $table->string('locale', 8)->default('fr')->index();
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // 6. Table de traçabilité des sanctions et avertissements formels
        Schema::create('user_account_sanctions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 32)->index(); // 'warning', 'suspension', 'restriction'
            $table->unsignedTinyInteger('level')->nullable(); // 1, 2, 3 pour avertissements
            $table->unsignedInteger('duration_days')->nullable(); // 7, 30, null pour indéfini
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->text('reason');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('lifted_at')->nullable();
            $table->foreignId('lifted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('lift_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type', 'is_active']);
        });

        // 7. Table des demandes d'anonymisation RGPD soumises à double approbation
        Schema::create('user_anonymization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by_id')->constrained('users')->cascadeOnDelete();
            $table->text('reason');
            $table->string('status', 32)->default('pending_approval')->index(); // 'pending_approval', 'approved', 'rejected', 'processed'
            $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->json('execution_summary')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_anonymization_requests');
        Schema::dropIfExists('user_account_sanctions');
        Schema::dropIfExists('support_response_templates');
        Schema::dropIfExists('support_ticket_escalations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_certified', 'certified_at']);
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn(['retained_until']);
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropForeign(['resolved_by_id']);
            $table->dropColumn(['resolved_by_id', 'resolution_category', 'resolution_reason', 'channel']);
        });
    }
};
