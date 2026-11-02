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
        // 1. Table des règles d'agenda par défaut du bailleur (BL-08 compte)
        Schema::create('landlord_slot_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('days')->nullable();
            $table->unsignedInteger('minimum_hours')->default(4);
            $table->boolean('grouped')->default(false);
            $table->unsignedInteger('group_size')->default(5);
            $table->boolean('auto_confirm')->default(false);
            $table->timestamps();
        });

        // 2. Table des congés / indisponibilités du bailleur
        Schema::create('landlord_unavailabilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->dateTime('from');
            $table->dateTime('to');
            $table->timestamps();

            $table->index(['user_id', 'from', 'to']);
        });

        // 3. Ajouter auto_confirm sur listings pour la gestion par bien
        Schema::table('listings', function (Blueprint $table) {
            $table->boolean('auto_confirm')->default(false)->after('uses_agenda');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listings', function (Blueprint $table) {
            $table->dropColumn('auto_confirm');
        });

        Schema::dropIfExists('landlord_unavailabilities');
        Schema::dropIfExists('landlord_slot_rules');
    }
};
