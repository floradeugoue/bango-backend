<?php

use App\Services\Support\DemoAccountCleanupService;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Nettoyage automatique en production des 8 faux comptes de démonstration (Samuel Moukoko, etc.)
        // et de tous leurs contenus associés (posts, stories, reels, annonces, commentaires).
        $service = app(DemoAccountCleanupService::class);
        $service->purge();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Irréversible par nature (suppression de faux comptes de démonstration).
    }
};
