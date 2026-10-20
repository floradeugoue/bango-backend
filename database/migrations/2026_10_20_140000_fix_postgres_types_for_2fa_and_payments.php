<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN two_factor_secret TYPE TEXT;');
            DB::statement('ALTER TABLE payment_transactions ALTER COLUMN payable_id TYPE VARCHAR(64);');

            DB::statement('CREATE OR REPLACE FUNCTION uuid_max(uuid, uuid) RETURNS uuid AS $$ BEGIN RETURN CASE WHEN $1 IS NULL THEN $2 WHEN $2 IS NULL THEN $1 WHEN $1 > $2 THEN $1 ELSE $2 END; END; $$ LANGUAGE plpgsql IMMUTABLE;');
            DB::statement('CREATE OR REPLACE AGGREGATE max(uuid) (SFUNC = uuid_max, STYPE = uuid);');

            DB::statement('CREATE OR REPLACE FUNCTION uuid_min(uuid, uuid) RETURNS uuid AS $$ BEGIN RETURN CASE WHEN $1 IS NULL THEN $2 WHEN $2 IS NULL THEN $1 WHEN $1 < $2 THEN $1 ELSE $2 END; END; $$ LANGUAGE plpgsql IMMUTABLE;');
            DB::statement('CREATE OR REPLACE AGGREGATE min(uuid) (SFUNC = uuid_min, STYPE = uuid);');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users ALTER COLUMN two_factor_secret TYPE VARCHAR(255);');
            DB::statement('ALTER TABLE payment_transactions ALTER COLUMN payable_id TYPE BIGINT USING payable_id::bigint;');

            DB::statement('DROP AGGREGATE IF EXISTS max(uuid);');
            DB::statement('DROP FUNCTION IF EXISTS uuid_max(uuid, uuid);');
            DB::statement('DROP AGGREGATE IF EXISTS min(uuid);');
            DB::statement('DROP FUNCTION IF EXISTS uuid_min(uuid, uuid);');
        }
    }
};
