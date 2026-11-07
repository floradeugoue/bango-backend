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
        Schema::table('listings', function (Blueprint $table) {
            $table->index('reference', 'idx_listings_reference');
            $table->index(['status', 'created_at'], 'idx_listings_status_created_at');
        });

        Schema::table('visit_reservations', function (Blueprint $table) {
            if (! Schema::hasColumn('visit_reservations', 'reference')) {
                $table->string('reference', 64)->nullable()->index('idx_visit_reservations_reference');
            }
            $table->index(['status', 'created_at'], 'idx_visit_reservations_status_created_at');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS idx_listings_reference_trgm ON listings USING gin (reference gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS idx_listings_title_trgm ON listings USING gin (title gin_trgm_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS idx_listings_reference_trgm;');
            DB::statement('DROP INDEX IF EXISTS idx_listings_title_trgm;');
        }

        Schema::table('listings', function (Blueprint $table) {
            $table->dropIndex('idx_listings_reference');
            $table->dropIndex('idx_listings_status_created_at');
        });

        Schema::table('visit_reservations', function (Blueprint $table) {
            $table->dropIndex('idx_visit_reservations_status_created_at');
            if (Schema::hasColumn('visit_reservations', 'reference')) {
                $table->dropColumn('reference');
            }
        });
    }
};
