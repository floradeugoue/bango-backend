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
        if (! Schema::hasTable('help_articles')) {
            return;
        }

        // 1. Mise à jour de l'article sur la réservation de visite immobilière
        $updatedContent = "# Réservation d'une visite\n\nPour réserver une visite sur BANGO :\n1. Rends-toi sur l'annonce du bien immobilier.\n2. Clique sur **Réserver une visite**.\n3. Choisis le créneau souhaité et confirme le paiement (ou valide si la visite est gratuite).\n4. Le bailleur dispose de 24 heures pour accepter ou refuser ta demande.";

        DB::table('help_articles')
            ->where('slug', 'comment-reserver-une-visite')
            ->update([
                'title' => 'Comment réserver une visite immobilière ?',
                'content_markdown' => $updatedContent,
                'updated_at' => now(),
            ]);

        // 2. Remplacement préventif dans tout autre article contenant encore '18 heures'
        $articles = DB::table('help_articles')
            ->where('content_markdown', 'like', '%18 heures%')
            ->get();

        foreach ($articles as $article) {
            $content = str_replace(
                ['18 heures pour accepter ou refuser votre demande', '18 heures'],
                ['24 heures pour accepter ou refuser ta demande', '24 heures'],
                $article->content_markdown
            );

            DB::table('help_articles')
                ->where('id', $article->id)
                ->update([
                    'content_markdown' => $content,
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Ne pas réintroduire l'ancienne valeur erronée (18 heures / vouvoiement)
    }
};
