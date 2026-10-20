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
        Schema::table('moderation_appeals', function (Blueprint $table) {
            $table->foreignId('moderation_decision_id')
                ->nullable()
                ->after('user_id')
                ->constrained('moderation_decisions')
                ->nullOnDelete();

            $table->index('moderation_decision_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moderation_appeals', function (Blueprint $table) {
            $table->dropForeign(['moderation_decision_id']);
            $table->dropIndex(['moderation_decision_id']);
            $table->dropColumn('moderation_decision_id');
        });
    }
};
