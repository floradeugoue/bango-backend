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
        Schema::table('cities', function (Blueprint $table) {
            if (! Schema::hasColumn('cities', 'population')) {
                $table->bigInteger('population')->nullable()->index()->after('lng');
            }
            if (! Schema::hasColumn('cities', 'geoname_id')) {
                $table->bigInteger('geoname_id')->nullable()->index()->after('population');
            }
            if (! Schema::hasColumn('cities', 'osm_fetched_at')) {
                $table->timestamp('osm_fetched_at')->nullable()->after('geoname_id');
            }
        });

        Schema::table('neighbourhoods', function (Blueprint $table) {
            if (! Schema::hasColumn('neighbourhoods', 'status')) {
                $table->string('status', 30)->default('verified')->index()->after('is_active');
            }
            if (! Schema::hasColumn('neighbourhoods', 'source')) {
                $table->string('source', 30)->default('manual')->after('status');
            }
            if (! Schema::hasColumn('neighbourhoods', 'submitted_by_user_id')) {
                $table->foreignId('submitted_by_user_id')
                    ->nullable()
                    ->after('source')
                    ->constrained('users')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('neighbourhoods', 'osm_id')) {
                $table->string('osm_id', 60)->nullable()->index()->after('submitted_by_user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('neighbourhoods', function (Blueprint $table) {
            if (Schema::hasColumn('neighbourhoods', 'submitted_by_user_id')) {
                $table->dropForeign(['submitted_by_user_id']);
                $table->dropColumn(['submitted_by_user_id']);
            }
            if (Schema::hasColumn('neighbourhoods', 'osm_id')) {
                $table->dropColumn('osm_id');
            }
            if (Schema::hasColumn('neighbourhoods', 'source')) {
                $table->dropColumn('source');
            }
            if (Schema::hasColumn('neighbourhoods', 'status')) {
                $table->dropColumn('status');
            }
        });

        Schema::table('cities', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('cities', 'osm_fetched_at')) {
                $cols[] = 'osm_fetched_at';
            }
            if (Schema::hasColumn('cities', 'geoname_id')) {
                $cols[] = 'geoname_id';
            }
            if (Schema::hasColumn('cities', 'population')) {
                $cols[] = 'population';
            }
            if (! empty($cols)) {
                $table->dropColumn($cols);
            }
        });
    }
};
