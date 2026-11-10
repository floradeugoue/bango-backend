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
        Schema::table('moderation_filter_logs', function (Blueprint $table) {
            $table->unsignedTinyInteger('step')->default(1)->after('action_taken');
            $table->timestamp('restricted_until')->nullable()->after('step');
            $table->foreignId('sanction_id')->nullable()->after('restricted_until')->constrained('user_account_sanctions')->nullOnDelete();

            $table->index('step', 'moderation_filter_logs_step_idx');
            $table->index(['user_id', 'created_at'], 'moderation_filter_logs_user_created_idx');
            $table->index('created_at', 'moderation_filter_logs_created_at_idx');
        });

        Schema::table('user_account_sanctions', function (Blueprint $table) {
            $table->foreignId('operator_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moderation_filter_logs', function (Blueprint $table) {
            $table->dropForeign(['sanction_id']);
            $table->dropIndex('moderation_filter_logs_step_idx');
            $table->dropIndex('moderation_filter_logs_user_created_idx');
            $table->dropIndex('moderation_filter_logs_created_at_idx');
            $table->dropColumn(['step', 'restricted_until', 'sanction_id']);
        });
    }
};
