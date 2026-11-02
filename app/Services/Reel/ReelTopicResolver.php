<?php

namespace App\Services\Reel;

use App\Models\User;

class ReelTopicResolver
{
    /**
     * Dictionnaire d'inférence des thèmes d'après les mots-clés de la légende.
     * Les identifiants retournés correspondent aux IDs de /interests (realestate, food, music, etc.)
     * avec support pour l'alias historique 'housing' -> 'realestate'.
     *
     * @var array<string, array<int, string>>
     */
    protected const KEYWORD_MAP = [
        'realestate' => [
            'immo', 'immobilier', 'maison', 'villa', 'appartement', 'studio', 'chambre', 'loyer',
            'location', 'bailleur', 'terrain', 'parcelle', 'bâtiment', 'duplex', 'logement', 'meublé',
            'immeuble', 'colocation', 'résidence', 'propriétaire', 'visite',
        ],
        'food' => [
            'cuisine', 'recette', 'plat', 'repas', 'restaurant', 'resto', 'dîner', 'déjeuner',
            'petit-déjeuner', 'goûter', 'nourriture', 'food', 'chef', 'gâteau', 'sauce', 'poulet',
            'poisson', 'ndolé', 'taro', 'koki', 'beignets', 'boisson', 'cocktail', 'café', 'chocolat',
        ],
        'fashion' => [
            'mode', 'fashion', 'look', 'style', 'outfit', 'vêtement', 'robe', 'costume', 'chemise',
            'pantalon', 'chaussure', 'sneakers', 'tissu', 'pagne', 'afritude', 'coiffure', 'make-up',
            'maquillage', 'beauté', 'accessoire', 'sac', 'bijoux',
        ],
        'business' => [
            'business', 'commerce', 'vente', 'promo', 'promotion', 'offre', 'boutique', 'magasin',
            'prix', 'fcfa', 'xaf', 'investissement', 'startup', 'entrepreneur', 'entreprise',
            'argent', 'finances', 'opportunité', 'marketing', 'formation',
        ],
        'music' => [
            'musique', 'music', 'chanson', 'son', 'beat', 'clip', 'artiste', 'chanteur', 'rappeur',
            'rap', 'afrobeat', 'makossa', 'bikutsi', 'mbolé', 'danse', 'concert', 'album', 'single',
        ],
        'sport' => [
            'sport', 'football', 'foot', 'ballon', 'match', 'gym', 'fitness', 'entraînement',
            'musculation', 'course', 'running', 'basket', 'combat', 'boxe', 'tennis',
        ],
        'tech' => [
            'tech', 'technologie', 'informatique', 'ordinateur', 'pc', 'smartphone', 'téléphone',
            'iphone', 'android', 'application', 'logiciel', 'code', 'programmeur', 'ia', 'ai',
        ],
        'travel' => [
            'voyage', 'travel', 'trip', 'tourisme', 'vacances', 'vol', 'avion', 'hôtel', 'plage',
            'découverte', 'aventure', 'destination', 'paysage',
        ],
        'education' => [
            'éducation', 'education', 'école', 'université', 'cours', 'étudiant', 'diplôme',
            'livre', 'lecture', 'apprentissage', 'tuto', 'tutoriel', 'astuce', 'conseil',
        ],
        'entertainment' => [
            'humour', 'drôle', 'comédie', 'rire', 'sketch', 'blague', 'buzz', 'divertissement',
            'cinéma', 'film', 'série', 'acteur', 'gaming', 'jeu',
        ],
    ];

    /**
     * Résout le topic selon la priorité produit :
     * 1. Si input direct fourni, normalise et accepte (housing -> realestate)
     * 2. Si lié à un bien immobilier (listing_id) -> 'realestate'
     * 3. D'après les mots-clés de la légende
     * 4. D'après le premier centre d'intérêt (/interests) de l'auteur
     * 5. À défaut -> 'neighbourhood'
     */
    public function resolve(?string $topic = null, ?string $caption = null, ?string $listingId = null, ?User $user = null): string
    {
        // 1. Topic fourni explicitement
        if ($topic !== null && trim($topic) !== '') {
            $normalized = $this->normalizeTopic(trim($topic));
            if ($normalized !== 'neighbourhood') {
                return $normalized;
            }
        }

        // 2. Si lié à une annonce immobilière
        if (! empty($listingId)) {
            return 'realestate';
        }

        // 3. Analyse de la légende
        if (! empty($caption)) {
            $detected = $this->detectFromCaption($caption);
            if ($detected !== null) {
                return $detected;
            }
        }

        // 4. Premier centre d'intérêt de l'auteur
        if ($user) {
            $firstInterest = $this->getFirstUserInterest($user);
            if ($firstInterest !== null) {
                return $firstInterest;
            }
        }

        // 5. Thème générique par défaut
        return 'neighbourhood';
    }

    /**
     * Normalise un topic (ex: 'housing' ou 'immo' -> 'realestate').
     */
    public function normalizeTopic(string $topic): string
    {
        $lowered = strtolower(trim($topic));

        return match ($lowered) {
            'housing', 'immo', 'immobilier' => 'realestate',
            'cuisine', 'recette' => 'food',
            'mode' => 'fashion',
            'musique' => 'music',
            'divertissement' => 'entertainment',
            'voyage' => 'travel',
            'éducation' => 'education',
            default => $lowered,
        };
    }

    /**
     * Détecte un topic en scannant la légende avec les mots-clés et en retenant le thème le plus pertinent.
     */
    protected function detectFromCaption(string $caption): ?string
    {
        $normalizedText = ' '.mb_strtolower($caption, 'UTF-8').' ';

        // Remplacement préventif de l'expression culinaire 'fait maison' pour éviter une fausse détection immobilière
        $searchableText = str_replace(' fait maison ', ' fait_maison ', $normalizedText);

        $scores = [];

        foreach (self::KEYWORD_MAP as $topic => $keywords) {
            $count = 0;
            foreach ($keywords as $keyword) {
                // Recherche avec délimiteur de mot ou ponctuation
                $pattern = '/[\s,.;:!?\'"«»\(\)\[\]{}]'.preg_quote($keyword, '/').'[\s,.;:!?\'"«»\(\)\[\]{}]/u';
                if (preg_match($pattern, $searchableText)) {
                    $count++;
                }
            }
            if ($count > 0) {
                $scores[$topic] = $count;
            }
        }

        if (empty($scores)) {
            return null;
        }

        // Retourne le topic avec le score le plus élevé
        arsort($scores);

        return array_key_first($scores);
    }

    /**
     * Récupère le premier centre d'intérêt de l'auteur.
     */
    protected function getFirstUserInterest(User $user): ?string
    {
        // Si la relation interests est déjà chargée
        if ($user->relationLoaded('interests')) {
            $first = $user->interests->first();

            return $first?->id;
        }

        $first = $user->interests()->first();

        return $first?->id;
    }
}
