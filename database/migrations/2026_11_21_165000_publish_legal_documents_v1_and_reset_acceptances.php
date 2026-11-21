<?php

use App\Models\Legal\LegalDocument;
use App\Models\Legal\TermsAcceptance;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        // 1. Suppression définitive des versions provisoires 3.0
        LegalDocument::where('version', '3.0')->delete();

        // 2. Publication des six textes v1.0 avec date d'effet au 22/11/2026
        $textesDir = base_path('docs/juridique/textes-1.0');

        $documents = [
            [
                'slug' => 'terms-of-service',
                'file' => 'terms-of-service.fr.md',
                'locale' => 'fr',
                'title' => 'Conditions générales d’utilisation',
                'is_mandatory' => true,
            ],
            [
                'slug' => 'cgu',
                'file' => 'terms-of-service.fr.md',
                'locale' => 'fr',
                'title' => 'Conditions générales d’utilisation',
                'is_mandatory' => true,
            ],
            [
                'slug' => 'terms-of-service',
                'file' => 'terms-of-service.en.md',
                'locale' => 'en',
                'title' => 'Terms of Use',
                'is_mandatory' => true,
            ],
            [
                'slug' => 'privacy-policy',
                'file' => 'privacy-policy.fr.md',
                'locale' => 'fr',
                'title' => 'Politique de confidentialité',
                'is_mandatory' => true,
            ],
            [
                'slug' => 'privacy-policy',
                'file' => 'privacy-policy.en.md',
                'locale' => 'en',
                'title' => 'Privacy Policy',
                'is_mandatory' => true,
            ],
            [
                'slug' => 'content-rules',
                'file' => 'content-rules.fr.md',
                'locale' => 'fr',
                'title' => 'Règles de contenu',
                'is_mandatory' => false,
            ],
            [
                'slug' => 'content-rules',
                'file' => 'content-rules.en.md',
                'locale' => 'en',
                'title' => 'Content rules',
                'is_mandatory' => false,
            ],
        ];

        foreach ($documents as $docConfig) {
            $filePath = $textesDir.DIRECTORY_SEPARATOR.$docConfig['file'];
            if (! File::exists($filePath)) {
                continue;
            }

            $content = File::get($filePath);

            LegalDocument::updateOrCreate(
                [
                    'slug' => $docConfig['slug'],
                    'version' => '1.0',
                    'locale' => $docConfig['locale'],
                ],
                [
                    'title' => $docConfig['title'],
                    'content_markdown' => $content,
                    'effective_date' => '2026-11-22 00:00:00',
                    'is_published' => true,
                    'published_at' => '2026-11-22 00:00:00',
                    'is_mandatory' => $docConfig['is_mandatory'],
                ]
            );
        }

        // 3. Remise à zéro des acceptations de test pour forcer l'acceptation de la 1.0 par chaque compte
        TermsAcceptance::query()->delete();
        User::query()->update([
            'accepted_terms_version' => null,
            'terms_accepted_at' => null,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pas de retour arrière nécessaire sur la remise à zéro des acceptations de test
    }
};
