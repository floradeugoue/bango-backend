# JOURNAL DES MODIFICATIONS (CHANGELOG) — MODULE CR1 SQUARE

## Version 1.0.0 — 2026-10-14

### Ajouts (Added)
- **Base de données & Migrations** :
  - Création de la migration `2026_10_14_150000_create_square_tables.php` pour `posts`, `post_media`, `post_tags`, `hashtags`, `post_hashtag`.
- **Modèles Eloquent & Enums** :
  - `App\Models\Square\Post` avec relations `media`, `hashtags`, `tags`, `user` et casts stricts.
  - `App\Models\Square\PostMedia` avec gestion de l'ordre de carrousel et métadonnées de retouche.
  - `App\Models\Square\PostTag` pour les personnes identifiées avec coordonnées `x`, `y` (0..1).
  - `App\Models\Square\Hashtag` avec normalisation automatique et comptage dénormalisé.
  - `PostAudience`, `PostCommentsBy`, `PostMediaKind` dans `App\Enums\Square\`.
  - Relations `posts()` et `taggedInMedia()` ajoutées au modèle `User`.
- **Services Métier** :
  - `PostCreationService` : Création transactionnelle et idempotente de posts avec médias, tags et hashtags.
  - `PostManagementService` : Gestion post-publication SQ-15 (légende, audience, commentaires, archivage, suppression).
  - `PostFeedService` : Restitution paginée du fil Square avec filtres `discover`, `following`, `neighbourhood`.
  - `HashtagExtractionService` : Extraction regex Unicode, normalisation et synchronisation des compteurs.
  - `MediaUploadService` : Validation MIME stricte, limitation de taille (15MB/100MB), calcul de dimensions et stockage public.
- **FormRequests & Resources** :
  - `CreatePostRequest`, `UpdateCaptionRequest`, `UpdateAudienceRequest`, `UpdateCommentsStatusRequest`, `UploadMediaRequest`.
  - `PostResource`, `PostMediaResource`, `PostTagResource`, `PeopleSearchResultResource`, `HashtagSearchResultResource`.
- **Politiques de Sécurité (Policies)** :
  - `PostPolicy` protégeant les actions de modification, archivage et suppression (anti-IDOR) et la visibilité d'audience.
- **Contrôleurs API & Routage** :
  - `PostController`, `PostFeedController`, `PostCaptionController`, `PostAudienceController`, `PostCommentsStatusController`, `PostArchiveController`.
  - `MediaUploadController`, `PeopleSearchController`, `HashtagSearchController`, `PlaceSearchController`.
  - Découpage modulaire dans `routes/api/v1/square.php`, `media.php`, `search.php`.
- **Tests Automatisés** :
  - Suite de 20 tests Pest couvrant l'intégralité du module Square (CR-01 à CR-14, SQ-15, recherche, sécurité et uploads).
- **Documentation Complète** :
  - Rédaction de 9 documents d'architecture, d'audit, de schéma, de sécurité, d'API et de guides de test/déploiement dans `docs/`.
