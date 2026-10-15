# GUIDE DE TEST AUTOMATISÉ — MODULE CR1 SQUARE BANGO

## 1. Vue d'Ensemble des Tests
Le module Square dispose d'une couverture de tests automatisés exhaustive écrite avec **Pest PHP**.

### 1.1. Fichiers de Tests
- `tests/Feature/Square/PostCreationTest.php` : Création de publication texte, médias/carrousel, tags de personnes, extraction et normalisation de hashtags, rejets de validation (texte vide, >10 médias), idempotence réseau, authentification Passport.
- `tests/Feature/Square/PostManagementTest.php` : Modification de légende, modification d'audience, verrouillage/déverrouillage des commentaires, archivage/désarchivage, suppression avec décrémentation des compteurs de hashtags, protection IDOR (403).
- `tests/Feature/Square/PostFeedTest.php` : Restitution paginée du fil Square, masquage des posts archivés, filtrage par onglet `following`, filtrage par onglet `neighbourhood`, vue profil auteur.
- `tests/Feature/Square/MediaAndSearchTest.php` : Téléversement multipart d'images, rejet de types de fichiers non autorisés, recherche de personnes par nom/pseudo, recherche de hashtags tendances, recherche contextuelle de lieux réels.

---

## 2. Commandes d'Exécution

### 2.1. Exécuter uniquement les tests Square
```bash
php artisan test tests/Feature/Square --compact
```

### 2.2. Exécuter toute la suite de tests du backend
```bash
php artisan test --compact
```

---

## 3. Résultats Obtenus
- **Tests Square** : 20 passés sur 20 (88 assertions).
- **Suite globale BANGO** : 247 passés sur 247 (1 271 assertions).
- **Temps d'exécution global** : ~17 secondes.
- **Taux de succès** : 100%.
