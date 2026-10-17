<?php

namespace Database\Seeders;

use App\Models\Support\ModerationRule;
use Illuminate\Database\Seeder;

class ModerationRuleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $rules = [
            [
                'code' => 'spam',
                'name' => 'Spam & Publicité abusive',
                'description' => 'Messages non sollicités, liens répétitifs, promotion non autorisée.',
                'app_reason' => 'spam',
                'severity' => 'low',
                'default_action' => 'remove',
            ],
            [
                'code' => 'fraud',
                'name' => 'Arnaque & Tentative de fraude',
                'description' => 'Demande d\'argent hors plateforme, phishing, escroquerie financière.',
                'app_reason' => 'fraud',
                'severity' => 'critical',
                'default_action' => 'suspend_author',
            ],
            [
                'code' => 'harassment',
                'name' => 'Harcèlement & Intimidation',
                'description' => 'Menaces répétées, dénigrement, incitation au harcèlement de meute.',
                'app_reason' => 'harassment',
                'severity' => 'high',
                'default_action' => 'warn_author',
            ],
            [
                'code' => 'inappropriate_content',
                'name' => 'Contenu Inapproprié ou Explicite',
                'description' => 'Nudité, actes sexuellement suggestifs, contenu violent non averti.',
                'app_reason' => 'inappropriate_content',
                'severity' => 'high',
                'default_action' => 'hide',
            ],
            [
                'code' => 'copyright',
                'name' => 'Atteinte aux Droits d\'Auteur',
                'description' => 'Reproduction sans droit d\'œuvre protégée, audio non licencié, contrefaçon.',
                'app_reason' => 'copyright',
                'severity' => 'medium',
                'default_action' => 'remove',
            ],
            [
                'code' => 'fake_listing',
                'name' => 'Fausse Annonce Immobilière',
                'description' => 'Bien inexistant, photos trompeuses, usurpation d\'identité de bailleur.',
                'app_reason' => 'fake_listing',
                'severity' => 'critical',
                'default_action' => 'remove',
            ],
            [
                'code' => 'violence',
                'name' => 'Incitation à la Violence',
                'description' => 'Appels à la haine, violence physique, armes ou activités illégales.',
                'app_reason' => 'violence',
                'severity' => 'critical',
                'default_action' => 'suspend_author',
            ],
            [
                'code' => 'hate_speech',
                'name' => 'Discours Haineux & Discriminatoire',
                'description' => 'Propos injurieux ciblant une ethnie, religion, genre ou communauté.',
                'app_reason' => 'hate_speech',
                'severity' => 'critical',
                'default_action' => 'suspend_author',
            ],
        ];

        foreach ($rules as $data) {
            ModerationRule::updateOrCreate(
                ['code' => $data['code']],
                $data
            );
        }
    }
}
