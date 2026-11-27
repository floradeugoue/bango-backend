<?php

use Database\Seeders\NeighbourhoodSeeder;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        // Ensemence la liste exhaustive des quartiers de Douala
        app(NeighbourhoodSeeder::class)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pas de suppression requise
    }
};
