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
        Schema::table('reports', function (Blueprint $table) {
            $table->index('created_at', 'reports_created_at_idx');
            $table->index(['status', 'created_at'], 'reports_status_created_at_idx');
        });

        Schema::table('moderation_decisions', function (Blueprint $table) {
            $table->index(['operator_id', 'created_at'], 'moderation_decisions_operator_created_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex('reports_created_at_idx');
            $table->dropIndex('reports_status_created_at_idx');
        });

        Schema::table('moderation_decisions', function (Blueprint $table) {
            $table->dropIndex('moderation_decisions_operator_created_idx');
        });
    }
};
