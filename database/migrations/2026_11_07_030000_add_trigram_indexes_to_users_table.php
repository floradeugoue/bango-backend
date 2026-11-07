<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            DB::statement('CREATE INDEX IF NOT EXISTS users_name_trgm_idx ON users USING gin (name gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS users_handle_trgm_idx ON users USING gin (handle gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS users_email_trgm_idx ON users USING gin (email gin_trgm_ops);');
            DB::statement('CREATE INDEX IF NOT EXISTS users_display_name_trgm_idx ON users USING gin (display_name gin_trgm_ops);');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_name_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS users_handle_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS users_email_trgm_idx;');
            DB::statement('DROP INDEX IF EXISTS users_display_name_trgm_idx;');
        }
    }
};
