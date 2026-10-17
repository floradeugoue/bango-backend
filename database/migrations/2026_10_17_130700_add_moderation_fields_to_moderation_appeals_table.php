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
            $table->string('reference')->nullable()->unique()->after('id');
            $table->string('resolution')->nullable()->after('status'); // 'upheld', 'revoked'
            $table->foreignId('sanction_operator_id')->nullable()->after('sanction_reference')->constrained('users')->nullOnDelete();

            $table->index('reference');
            $table->index('resolution');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('moderation_appeals', function (Blueprint $table) {
            $table->dropForeign(['sanction_operator_id']);
            $table->dropIndex(['reference']);
            $table->dropIndex(['resolution']);
            $table->dropColumn(['reference', 'resolution', 'sanction_operator_id']);
        });
    }
};
