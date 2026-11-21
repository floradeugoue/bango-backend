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
        DB::table('sounds')->update([
            'base_uses' => 0,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pas de retour arrière pour la suppression des valeurs amorcées fictives
    }
};
