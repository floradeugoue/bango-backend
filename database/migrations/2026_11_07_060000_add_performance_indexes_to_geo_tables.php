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
        Schema::table('cities', function (Blueprint $table) {
            $table->index(['country_id', 'is_active'], 'cities_country_active_idx');
            $table->index(['name'], 'cities_name_idx');
        });

        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->index(['city_id', 'is_active', 'is_ignored'], 'neighbourhoods_city_active_idx');
            $table->index(['name'], 'neighbourhoods_name_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE INDEX IF NOT EXISTS cities_name_trgm_idx ON cities USING gin (name gin_trgm_ops)');
            DB::statement('CREATE INDEX IF NOT EXISTS neighbourhoods_name_trgm_idx ON neighbourhoods USING gin (name gin_trgm_ops)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS cities_name_trgm_idx');
            DB::statement('DROP INDEX IF EXISTS neighbourhoods_name_trgm_idx');
        }

        Schema::table('neighbourhoods', function (Blueprint $table) {
            $table->dropIndex('neighbourhoods_city_active_idx');
            $table->dropIndex('neighbourhoods_name_idx');
        });

        Schema::table('cities', function (Blueprint $table) {
            $table->dropIndex('cities_country_active_idx');
            $table->dropIndex('cities_name_idx');
        });
    }
};
