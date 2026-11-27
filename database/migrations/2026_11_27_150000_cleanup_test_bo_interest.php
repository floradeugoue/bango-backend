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
        // 1. Si la table pivot interest_user existe, détacher l'intérêt de test
        if (Schema::hasTable('interest_user')) {
            DB::table('interest_user')
                ->whereIn('interest_id', function ($query) {
                    $query->select('id')->from('interests')
                        ->where('id', 'test-bo-interest')
                        ->orWhere('name_fr', 'like', '%Test BO%')
                        ->orWhere('name_en', 'like', '%Test BO%');
                })
                ->delete();
        }

        // 2. Supprimer tout centre d'intérêt de test résiduel
        if (Schema::hasTable('interests')) {
            $query = DB::table('interests')
                ->where('id', 'test-bo-interest')
                ->orWhere('name_fr', 'like', '%Test BO%')
                ->orWhere('name_en', 'like', '%Test BO%');

            if (Schema::hasColumn('interests', 'slug')) {
                $query->orWhere('slug', 'test-bo-interest')
                    ->orWhere('slug', 'like', '%test-bo%');
            }

            $query->delete();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pas de réinsertion d'une donnée de test
    }
};
