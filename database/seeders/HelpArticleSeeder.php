<?php

namespace Database\Seeders;

use App\Enums\Support\HelpArticleCategory;
use App\Models\Support\HelpArticle;
use Illuminate\Database\Seeder;

class HelpArticleSeeder extends Seeder
{
    /**
     * Seed initial FAQ and help articles.
     */
    public function run(): void
    {
        $articles = [
            [
                'slug' => 'comment-reserver-une-visite',
                'category' => HelpArticleCategory::RealEstateVisits,
                'title' => 'Comment réserver une visite immobilière ?',
                'content_markdown' => "# Réservation d'une visite\n\nPour réserver une visite sur BANGO :\n1. Rends-toi sur l'annonce du bien immobilier.\n2. Clique sur **Réserver une visite**.\n3. Choisis le créneau souhaité et confirme le paiement (ou valide si la visite est gratuite).\n4. Le bailleur dispose de 24 heures pour accepter ou refuser ta demande.",
                'locale' => 'fr',
                'is_published' => true,
                'published_at' => now(),
                'order' => 1,
            ],
            [
                'slug' => 'securite-et-signalement-de-contenu',
                'category' => HelpArticleCategory::SecurityReport,
                'title' => 'Comment signaler un contenu abusif ou une fraude ?',
                'content_markdown' => "# Sécurité et signalement\n\nLa sécurité de notre communauté est primordiale :\n- Utilise le bouton **Signaler** sur toute publication, annonce ou message suspect.\n- En cas d'atteinte au droit d'auteur, utilise le formulaire dédié de signalement de droits.\n- Notre équipe de modération traite les signalements 24/7.",
                'locale' => 'fr',
                'is_published' => true,
                'published_at' => now(),
                'order' => 2,
            ],
            [
                'slug' => 'gerer-son-portefeuille-et-ses-paiements',
                'category' => HelpArticleCategory::PaymentWallet,
                'title' => 'Comment recharger son portefeuille et payer ses services ?',
                'content_markdown' => "# Portefeuille et paiements\n\nBANGO accepte Mobile Money (MTN, Orange) et les cartes bancaires via nos partenaires agréés.\nTes fonds sont sécurisés et tes factures sont téléchargeables immédiatement après confirmation.",
                'locale' => 'fr',
                'is_published' => true,
                'published_at' => now(),
                'order' => 3,
            ],
            [
                'slug' => 'certification-et-badge-verifie',
                'category' => HelpArticleCategory::SubscriptionBadge,
                'title' => 'Comment obtenir le badge vérifié et certifié ?',
                'content_markdown' => "# Certification de compte\n\nPour certifier ton profil professionnel ou ta page d'agence :\n1. Fournis tes pièces d'identité ou justificatifs d'entreprise.\n2. Notre équipe étudie ton dossier sous 48 heures.\n3. Dès validation, ton badge bleu s'affiche sur toutes tes annonces.",
                'locale' => 'fr',
                'is_published' => true,
                'published_at' => now(),
                'order' => 4,
            ],
            [
                'slug' => 'creer-et-gerer-une-page-professionnelle',
                'category' => HelpArticleCategory::ProPages,
                'title' => 'Comment gérer une page professionnelle et déléguer des accès ?',
                'content_markdown' => "# Pages Pro et Délégations\n\nLes agences et propriétaires peuvent créer des pages professionnelles pour regrouper leurs biens et désigner des agents délégués.",
                'locale' => 'fr',
                'is_published' => true,
                'published_at' => now(),
                'order' => 5,
            ],
        ];

        foreach ($articles as $article) {
            HelpArticle::firstOrCreate(
                ['slug' => $article['slug'], 'locale' => $article['locale']],
                $article
            );
        }
    }
}
