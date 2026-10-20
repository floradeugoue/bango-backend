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
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('withdrawn_by_bango')->default(false)->after('status');
            $table->timestamp('withdrawn_by_bango_at')->nullable()->after('withdrawn_by_bango');
            $table->text('bango_withdrawal_reason')->nullable()->after('withdrawn_by_bango_at');

            $table->index('withdrawn_by_bango');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex(['withdrawn_by_bango']);
            $table->dropColumn(['withdrawn_by_bango', 'withdrawn_by_bango_at', 'bango_withdrawal_reason']);
        });
    }
};
