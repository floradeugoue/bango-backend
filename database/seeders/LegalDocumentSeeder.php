<?php

namespace Database\Seeders;

use App\Models\Legal\LegalDocument;
use Illuminate\Database\Seeder;

class LegalDocumentSeeder extends Seeder
{
    /**
     * Seed initial legal documents (CGU, Confidentialité, Mentions légales).
     */
    public function run(): void
    {
        $documents = [
            [
                'slug' => 'cgu',
                'version' => '1.0',
                'locale' => 'fr',
                'title' => 'Conditions Générales d’Utilisation',
                'content_markdown' => "# Conditions Générales d’Utilisation (CGU)\n\nBienvenue sur BANGO. En accédant à nos services, vous acceptez sans réserve les présentes conditions...\n\n### 1. Objet\nBANGO fournit une plateforme technologique facilitant les interactions et transactions immobilières.\n\n### 2. Responsabilités\nChaque utilisateur s'engage à fournir des informations exactes et conformes aux lois en vigueur.",
                'effective_date' => now()->subDay(),
                'is_published' => true,
                'published_at' => now()->subDay(),
                'is_mandatory' => true,
            ],
            [
                'slug' => 'privacy',
                'version' => '1.0',
                'locale' => 'fr',
                'title' => 'Politique de Confidentialité et Protection des Données',
                'content_markdown' => "# Politique de Confidentialité\n\nNous accordons une importance capitale à la protection de vos données personnelles conformément aux réglementations applicables.\n\n### Données collectées\nNous collectons les données nécessaires au bon fonctionnement de nos services (identité, coordonnées, justificatifs de vérification).",
                'effective_date' => now()->subDay(),
                'is_published' => true,
                'published_at' => now()->subDay(),
                'is_mandatory' => true,
            ],
            [
                'slug' => 'mentions-legales',
                'version' => '1.0',
                'locale' => 'fr',
                'title' => 'Mentions Légales',
                'content_markdown' => "# Mentions Légales\n\nPlateforme éditée par BANGO SAS.\nHébergement : Infrastructures cloud certifiées ISO 27001.\nContact : support@bango.app",
                'effective_date' => now()->subDay(),
                'is_published' => true,
                'published_at' => now()->subDay(),
                'is_mandatory' => false,
            ],
        ];

        foreach ($documents as $doc) {
            LegalDocument::firstOrCreate(
                [
                    'slug' => $doc['slug'],
                    'version' => $doc['version'],
                    'locale' => $doc['locale'],
                ],
                $doc
            );
        }
    }
}
