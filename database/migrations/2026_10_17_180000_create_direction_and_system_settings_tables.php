<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations for Direction, SHA-256 Audit Chain, System Settings, and Scheduled Reports.
     */
    public function up(): void
    {
        // 1. Colonnes cryptographiques sur le journal d'audit administratif existant
        if (Schema::hasTable('admin_audit_logs')) {
            Schema::table('admin_audit_logs', function (Blueprint $table) {
                if (! Schema::hasColumn('admin_audit_logs', 'target_type')) {
                    $table->string('target_type', 100)->nullable()->after('event');
                }
                if (! Schema::hasColumn('admin_audit_logs', 'target_id')) {
                    $table->string('target_id', 100)->nullable()->after('target_type');
                }
                if (! Schema::hasColumn('admin_audit_logs', 'target_label')) {
                    $table->string('target_label', 255)->nullable()->after('target_id');
                }
                if (! Schema::hasColumn('admin_audit_logs', 'prev_hash')) {
                    $table->string('prev_hash', 64)->nullable()->index()->after('context');
                }
                if (! Schema::hasColumn('admin_audit_logs', 'hash')) {
                    $table->string('hash', 64)->nullable()->index()->after('prev_hash');
                }
                if (! Schema::hasColumn('admin_audit_logs', 'is_tampered')) {
                    $table->boolean('is_tampered')->default(false)->after('hash');
                }
            });
        }

        // 2. Table de traçabilité des tentatives illicites de modification d'audit (sans altérer l'événement cible)
        if (! Schema::hasTable('admin_audit_tamper_attempts')) {
            Schema::create('admin_audit_tamper_attempts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('audit_log_id')->constrained('admin_audit_logs')->cascadeOnDelete();
                $table->foreignId('operator_id')->constrained('users')->cascadeOnDelete();
                $table->json('attempted_changes')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->string('detection_source', 50)->default('api_attempt');
                $table->timestamp('created_at')->useCurrent();

                $table->index(['audit_log_id', 'created_at']);
            });
        }

        // 3. Table des exports d'audit scellés cryptographiquement par SHA-256
        if (! Schema::hasTable('admin_audit_exports')) {
            Schema::create('admin_audit_exports', function (Blueprint $table) {
                $table->id();
                $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
                $table->string('filename');
                $table->string('file_path');
                $table->json('filters')->nullable();
                $table->integer('records_count')->default(0);
                $table->string('sha256_seal', 64);
                $table->string('status', 30)->default('completed'); // completed, expired, revoked
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();

                $table->index(['requester_id', 'created_at']);
            });
        }

        // 4. Table des configurations système (seuils de modération, maintenance, incident, délais)
        if (! Schema::hasTable('system_configurations')) {
            Schema::create('system_configurations', function (Blueprint $table) {
                $table->id();
                $table->string('key', 100)->unique();
                $table->string('group', 50)->index(); // 'thresholds', 'maintenance', 'service', 'delays', 'pricing'
                $table->json('value')->nullable();
                $table->string('type', 30)->default('string'); // 'string', 'integer', 'float', 'boolean', 'json'
                $table->text('description')->nullable();
                $table->boolean('is_public')->default(false);
                $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['group', 'key']);
            });
        }

        // 5. Ajout de is_ignored sur les quartiers (areas) pour le filtrage cartographique
        if (Schema::hasTable('neighbourhoods')) {
            Schema::table('neighbourhoods', function (Blueprint $table) {
                if (! Schema::hasColumn('neighbourhoods', 'is_ignored')) {
                    $table->boolean('is_ignored')->default(false)->after('is_active');
                }
            });
        }

        // 6. Ajout des colonnes de suspension et de bascule sur les opérateurs Mobile Money
        if (Schema::hasTable('operators')) {
            Schema::table('operators', function (Blueprint $table) {
                if (! Schema::hasColumn('operators', 'suspended_at')) {
                    $table->timestamp('suspended_at')->nullable()->after('is_active');
                }
                if (! Schema::hasColumn('operators', 'suspension_reason')) {
                    $table->text('suspension_reason')->nullable()->after('suspended_at');
                }
                if (! Schema::hasColumn('operators', 'suspended_by_id')) {
                    $table->foreignId('suspended_by_id')->nullable()->constrained('users')->nullOnDelete()->after('suspension_reason');
                }
                if (! Schema::hasColumn('operators', 'fallback_operator_id')) {
                    $table->foreignId('fallback_operator_id')->nullable()->constrained('operators')->nullOnDelete()->after('suspended_by_id');
                }
            });
        }

        // 7. Table des propositions de changements tarifaires programmés
        if (! Schema::hasTable('pricing_change_proposals')) {
            Schema::create('pricing_change_proposals', function (Blueprint $table) {
                $table->id();
                $table->foreignId('price_id')->constrained('subscription_prices')->cascadeOnDelete();
                $table->foreignId('subscription_price_id')->nullable()->constrained('subscription_prices')->cascadeOnDelete();
                $table->foreignId('proposed_by_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('approved_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->decimal('new_price_amount', 12, 2);
                $table->integer('notice_days')->default(30);
                $table->timestamp('effective_date');
                $table->string('status', 30)->default('scheduled'); // 'scheduled', 'applied', 'cancelled'
                $table->text('reason')->nullable();
                $table->json('simulation_impact')->nullable();
                $table->timestamps();

                $table->index(['price_id', 'status']);
                $table->index(['status', 'effective_date']);
            });
        }

        // 8. Table des rapports périodiques programmés par email
        if (! Schema::hasTable('scheduled_reports')) {
            Schema::create('scheduled_reports', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('cadence', 30); // 'daily', 'weekly', 'monthly'
                $table->json('recipients');
                $table->json('metrics');
                $table->string('format', 20)->default('pdf');
                $table->boolean('is_active')->default(true);
                $table->timestamp('last_sent_at')->nullable();
                $table->timestamp('next_run_at')->nullable();
                $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->index(['cadence', 'is_active']);
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('scheduled_reports');
        Schema::dropIfExists('pricing_change_proposals');

        if (Schema::hasTable('operators')) {
            Schema::table('operators', function (Blueprint $table) {
                $table->dropForeign(['fallback_operator_id']);
                $table->dropForeign(['suspended_by_id']);
                $table->dropColumn(['suspended_at', 'suspension_reason', 'suspended_by_id', 'fallback_operator_id']);
            });
        }

        if (Schema::hasTable('neighbourhoods')) {
            Schema::table('neighbourhoods', function (Blueprint $table) {
                $table->dropColumn('is_ignored');
            });
        }

        Schema::dropIfExists('system_configurations');
        Schema::dropIfExists('admin_audit_exports');
        Schema::dropIfExists('admin_audit_tamper_attempts');

        if (Schema::hasTable('admin_audit_logs')) {
            Schema::table('admin_audit_logs', function (Blueprint $table) {
                $table->dropColumn(['target_type', 'target_id', 'target_label', 'prev_hash', 'hash', 'is_tampered']);
            });
        }
    }
};
