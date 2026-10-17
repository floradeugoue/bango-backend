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
            $table->string('reference')->nullable()->unique()->after('id');
            $table->foreignId('assigned_to')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->string('priority')->default('normal')->after('assigned_to'); // 'normal', 'high', 'urgent'
            $table->foreignId('escalated_to')->nullable()->after('priority')->constrained('users')->nullOnDelete();
            $table->text('escalation_reason')->nullable()->after('escalated_to');

            $table->index('status');
            $table->index('assigned_to');
            $table->index('priority');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reports', function (Blueprint $table) {
            $table->dropForeign(['assigned_to']);
            $table->dropForeign(['escalated_to']);
            $table->dropIndex(['status']);
            $table->dropIndex(['assigned_to']);
            $table->dropIndex(['priority']);
            $table->dropColumn(['reference', 'assigned_to', 'priority', 'escalated_to', 'escalation_reason']);
        });
    }
};
