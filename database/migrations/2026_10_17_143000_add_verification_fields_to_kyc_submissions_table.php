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
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->string('reference', 32)->nullable()->unique()->after('id');
            $table->string('profile_category', 32)->nullable()->default('landlord')->after('status'); // landlord, agent, page
            $table->foreignId('escalated_to_id')->nullable()->after('decided_by_id')->constrained('users')->nullOnDelete();
            $table->foreignId('escalated_by_id')->nullable()->after('escalated_to_id')->constrained('users')->nullOnDelete();
            $table->text('escalation_reason')->nullable()->after('escalated_by_id');
            $table->dateTime('escalated_at')->nullable()->after('escalation_reason');

            $table->index(['profile_category', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('kyc_submissions', function (Blueprint $table) {
            $table->dropForeign(['escalated_to_id']);
            $table->dropForeign(['escalated_by_id']);
            $table->dropIndex(['profile_category', 'status']);
            $table->dropColumn([
                'reference',
                'profile_category',
                'escalated_to_id',
                'escalated_by_id',
                'escalation_reason',
                'escalated_at',
            ]);
        });
    }
};
