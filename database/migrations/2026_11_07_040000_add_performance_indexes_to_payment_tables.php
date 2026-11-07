<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->index(['status', 'created_at'], 'idx_payment_tx_status_created_at');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->index(['type', 'created_at'], 'idx_credit_tx_type_created_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_payment_tx_reference_trgm ON payment_transactions USING gin (reference gin_trgm_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_payment_tx_reference_trgm;');
        }

        Schema::table('payment_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_payment_tx_status_created_at');
        });

        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_credit_tx_type_created_at');
        });
    }
};
