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
        // 1. Désactivation des quartiers de test créés à Douala (Quartier Test BO, Test 41608)
        DB::table('neighbourhoods')
            ->whereIn('name', ['Quartier Test BO', 'Test 41608'])
            ->orWhere('name', 'like', '%Test BO%')
            ->orWhere('name', 'like', '%Test 41608%')
            ->orWhere('name', 'like', '%41608%')
            ->update(['is_active' => false]);

        // 2. Désactivation de la France (FR) du référentiel actif afin qu'elle n'apparaisse plus
        // dans la sélection des pays de résidence AU-08 (réservée aux 8 pays d'Afrique subsaharienne).
        DB::table('countries')
            ->where('code', 'FR')
            ->update(['is_active' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('countries')
            ->where('code', 'FR')
            ->update(['is_active' => true]);
    }
};
