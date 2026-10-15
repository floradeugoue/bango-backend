# AUDIT DU BACKEND EXISTANT — MODULE CR1 SQUARE BANGO

## 1. Contexte et Inspection Technique

- **Framework** : Laravel 13.35.0
- **Version PHP** : 8.4.19
- **Base de données** : PostgreSQL 16+ avec migrations actives
- **Authentification** : Laravel Passport avec le guard `auth:api` (OAuth2 Bearer token)
- **Suite de tests actuelle** : Pest (227 tests passants, 1 183 assertions)
- **Stockage** : Disque `public` configuré (`storage/app/public` lié symboliquement via `storage:link`)

---

## 2. Matrice d'Audit Fonctionnelle

| Fonctionnalité | Frontend | Backend existant | Écart | Action | Catégorie |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **Upload de médias** | Multipart `POST /api/v1/media/upload`, formats images/vidéos | Absent pour Square (existant uniquement pour avatar/kyc) | Pas d'endpoint unifié de téléversement média Square | Créer `MediaUploadController`, `MediaUploadService` avec validation stricte MIME, taille et stockage public | **Absent** |
| **Création d'un post** | `POST /api/v1/posts`, corps texte, médias, audience, lieu, options | Inexistant | Aucune table ni endpoint pour les posts Square | Créer migration `posts`, modèle `Post`, `PostCreationService`, FormRequest et contrôleur | **Absent** |
| **Carrousel & Couverture** | 1 à 10 médias ordonnés, couverture = index 0, métadonnées de retouche | Inexistant | Aucune table `post_media` | Créer migration `post_media` avec `display_order`, `edit_metadata`, `media_type` | **Absent** |
| **Mentions et hashtags** | Détection `#hashtag` et `@mention` dans la légende | Inexistant | Aucun système d'extraction ni table de hashtags | Créer migration `hashtags` et table pivot `post_hashtag`, service d'extraction Unicode regex | **Absent** |
| **Tags sur photo** | Coordonnées relatives x, y (0..1) par média, compte cible | Inexistant | Aucune table `post_tags` | Créer migration `post_tags` reliée à `post_media` et `users`, validation des coordonnées | **Absent** |
| **Lieux** | Descriptif : label, détail, ville, quartier | Inexistant sur les posts (mais tables `cities`, `neighbourhoods` existent) | Absence des colonnes descriptives de lieu sur le post | Ajouter colonnes descriptives de lieu sur `posts` et endpoint de recherche de lieux | **Absent** |
| **Fil Square** | `GET /api/v1/square/feed` avec pagination et filtrage | Inexistant | Aucun fil de publications | Implémenter `PostFeedController`, `PostFeedService` avec filtres public, neighbourhood et following | **Absent** |
| **Audience** | `public`, `followers`, `neighbourhood` | Inexistant | Pas d'Enum ni colonne d'audience | Créer Enum `PostAudience`, migration et endpoint `PATCH /posts/{id}/audience` | **Absent** |
| **Commentaires** | Options `everyone`, `followers`, `nobody`, `comments_closed` | Inexistant pour les posts | Pas de configuration de commentaires par post | Ajouter `comments_by` et `comments_closed` sur `posts`, endpoint `PATCH /posts/{id}/comments-status` | **Absent** |
| **Archivage** | Action SQ-15 pour masquer du fil public | Inexistant | Pas de statut d'archivage | Ajouter colonne `is_archived` sur `posts`, endpoints `POST /posts/{id}/archive` et `unarchive` | **Absent** |
| **Suppression** | Action SQ-15 avec contrôle auteur / admin | Inexistant | Pas de suppression sécurisée de post | Implémenter `DELETE /api/v1/posts/{id}` avec `PostPolicy` et suppression en cascade/soft-delete | **Absent** |
| **Reprise upload** | Client local `PublishJob` dans AsyncStorage avec retry | Pas de TUS côté serveur | Le frontend stocke les brouillons et retry en local, serveur doit être idempotent | Implémenter clé d'idempotence (`idempotency_key`) sur `POST /posts` et validation cohérente | **Absent** |

---

## 3. Synthèse des Catégories

1. **Déjà implémenté et conforme** :
   - Authentification OAuth2 Passport (`auth:api`).
   - Modèles `User`, `City`, `Neighbourhood` réutilisables pour les relations et la recherche.
   - Système de rôles et permissions (`admin`, etc.).

2. **Implémenté, mais incomplet** :
   - Système de recherche : il existe des données géographiques (`cities`, `neighbourhoods`) et d'utilisateurs (`users`), mais pas d'endpoints dédiés à la recherche contextuelle Square (`/search/people`, `/search/hashtags/trending`, `/search/places`).

3. **Absent** :
   - Tout le domaine Square (`posts`, `post_media`, `post_tags`, `hashtags`, `post_hashtag`).
   - Les routes `/api/v1/posts/*`, `/api/v1/square/*`, `/api/v1/media/upload`, `/api/v1/search/*`.

4. **Incompatible avec le frontend** :
   - Rien d'incompatible d'un point de vue architectural. Le backend doit simplement respecter scrupuleusement la casse, les types et les codes HTTP attendus par le client Expo.
