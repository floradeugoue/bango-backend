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
        if (! Schema::hasColumn('users', 'onboarding_completed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->timestamp('onboarding_completed_at')->nullable()->after('onboarding_progress');
            });
        }

        // Rattrapage automatique pour les comptes déjà inscrits ayant date de naissance et CGU acceptées
        DB::table('users')
            ->whereNotNull('birth_date')
            ->whereNotNull('terms_accepted_at')
            ->whereNull('onboarding_completed_at')
            ->update([
                'onboarding_completed_at' => DB::raw('COALESCE(terms_accepted_at, created_at, CURRENT_TIMESTAMP)'),
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('users', 'onboarding_completed_at')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('onboarding_completed_at');
            });
        }
    }
};
