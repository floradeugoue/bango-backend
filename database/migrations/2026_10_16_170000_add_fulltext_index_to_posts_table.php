<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (Schema::hasTable('posts') && DB::getDriverName() === 'pgsql') {
            // Index GIN PostgreSQL pour recherche plein texte en langue française sur le corps des publications
            DB::statement("CREATE INDEX IF NOT EXISTS posts_body_tsvector_idx ON posts USING gin(to_tsvector('french', coalesce(body, '')))");
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('posts') && DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS posts_body_tsvector_idx');
        }
    }
};
