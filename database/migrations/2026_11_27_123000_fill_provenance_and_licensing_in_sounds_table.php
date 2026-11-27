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
        // 1. Remplir la provenance et licence pour les sons du catalogue (non originaux)
        DB::table('sounds')
            ->where(function ($query) {
                $query->whereNull('provenance')
                    ->orWhere('provenance', '');
            })
            ->where('is_original', false)
            ->update([
                'provenance' => 'bango',
                'license_type' => DB::raw("COALESCE(license_type, 'royalty_free')"),
            ]);

        // 2. Remplir la provenance et licence pour les sons originaux des créateurs
        DB::table('sounds')
            ->where(function ($query) {
                $query->whereNull('provenance')
                    ->orWhere('provenance', '');
            })
            ->where('is_original', true)
            ->update([
                'provenance' => 'user',
                'license_type' => DB::raw("COALESCE(license_type, 'user')"),
            ]);

        // 3. Compléter tout type de licence manquant
        DB::table('sounds')
            ->where(function ($query) {
                $query->whereNull('license_type')
                    ->orWhere('license_type', '');
            })
            ->update([
                'license_type' => 'royalty_free',
            ]);

        // 4. Indexer la colonne provenance si pas déjà fait
        Schema::table('sounds', function (Blueprint $table) {
            $table->index('provenance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sounds', function (Blueprint $table) {
            $table->dropIndex(['provenance']);
        });
    }
};
