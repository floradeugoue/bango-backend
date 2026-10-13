<?php

namespace Database\Seeders;

use App\Models\Subscription\SubscriptionPrice;
use App\Models\Subscription\SubscriptionProduct;
use Illuminate\Database\Seeder;

class SubscriptionCatalogSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Badge Bailleur Certifié
        $landlord = SubscriptionProduct::updateOrCreate(
            ['code' => 'landlord'],
            [
                'name' => 'Badge Bailleur Certifié',
                'description' => 'Diffusez vos biens en direct sans intermédiaire et certifiez votre statut de bailleur.',
                'badge_type' => 'landlord',
                'target_role' => 'landlord',
                'features' => [
                    'Anneau vert officiel Bailleur sur votre profil',
                    'Publication illimitée de vos annonces de biens',
                    'Réception directe des contacts locataires qualifiés',
                    'Badge de confiance vérifié',
                ],
                'is_active' => true,
                'display_order' => 1,
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $landlord->id, 'cadence' => 'monthly'],
            [
                'price_amount' => 7500.00,
                'currency' => 'XAF',
                'duration_days' => 30,
                'savings_description' => null,
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $landlord->id, 'cadence' => 'yearly'],
            [
                'price_amount' => 75000.00,
                'currency' => 'XAF',
                'duration_days' => 365,
                'savings_description' => 'Économisez 15 000 FCFA (2 mois offerts)',
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );

        // 2. Badge Agent Immobilier
        $agent = SubscriptionProduct::updateOrCreate(
            ['code' => 'agent'],
            [
                'name' => 'Badge Agent Immobilier',
                'description' => 'Gérez vos mandats professionnels et diffusez les annonces de vos agences partenaires.',
                'badge_type' => 'agent',
                'target_role' => 'agent',
                'features' => [
                    'Anneau orange officiel Agent sur votre profil',
                    'Diffusion professionnelle de biens sous mandats',
                    'Visibilité prioritaire sur le catalogue d\'agents',
                    'Mise en relation directe avec les bailleurs',
                ],
                'is_active' => true,
                'display_order' => 2,
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $agent->id, 'cadence' => 'monthly'],
            [
                'price_amount' => 3500.00,
                'currency' => 'XAF',
                'duration_days' => 30,
                'savings_description' => null,
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $agent->id, 'cadence' => 'yearly'],
            [
                'price_amount' => 35000.00,
                'currency' => 'XAF',
                'duration_days' => 365,
                'savings_description' => 'Économisez 7 000 FCFA (2 mois offerts)',
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );

        // 3. Certification Page Pro
        $pagePro = SubscriptionProduct::updateOrCreate(
            ['code' => 'page_pro'],
            [
                'name' => 'Certification Page Pro',
                'description' => 'Audit documentaire légal de votre agence ou société (RCCM, gérance).',
                'badge_type' => 'page',
                'target_role' => 'page',
                'features' => [
                    'Bouclier certifié officiel et anneau rouge BANGO',
                    'Vérification légale complète sous 24 à 48h',
                    'Jusqu\'à 2 soumissions correctives incluses',
                    'Gestion multi-utilisateurs et rôles d\'équipe',
                ],
                'is_active' => true,
                'display_order' => 3,
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $pagePro->id, 'cadence' => 'one_off'],
            [
                'price_amount' => 15000.00,
                'currency' => 'XAF',
                'duration_days' => 365,
                'savings_description' => 'Frais d\'examen uniques avec 2 corrections incluses',
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );

        // 4. Boost d'Annonce (Listing Boost)
        $boost = SubscriptionProduct::updateOrCreate(
            ['code' => 'listing_boost'],
            [
                'name' => 'Boost de Visibilité d\'Annonce',
                'description' => 'Multipliez par 5 la visibilité de votre annonce dans les résultats de recherche.',
                'badge_type' => null,
                'target_role' => null,
                'features' => [
                    'Positionnement sponsorisé en haut des recherches',
                    'Ciblage géographique par ville et quartier',
                    'Rapport détaillé d\'impressions et de clics',
                ],
                'is_active' => true,
                'display_order' => 4,
            ]
        );

        SubscriptionPrice::updateOrCreate(
            ['product_id' => $boost->id, 'cadence' => 'cpm'],
            [
                'price_amount' => 2180.00,
                'currency' => 'XAF',
                'duration_days' => 7,
                'savings_description' => 'Coût pour 1 000 impressions ciblées (CPM)',
                'is_active' => true,
                'effective_from' => now()->subMonth(),
            ]
        );
    }
}
