# Documentation API — Module CR3 Story (BANGO)

Ce document décrit l'ensemble des points d'accès REST développés pour le module **CR3 — Story** et les **Collections À la une (Highlights)** sur le backend BANGO Laravel.

---

## 1. Vue d'ensemble & Architecture

Le module CR3 permet aux utilisateurs de capturer et publier des photos et vidéos verticales éphémères (durée de validité : **24 heures**), de les enrichir avec des stickers créatifs (texte, sondages, questions, mentions, lieux, compte à rebours, annonces immobilières et sons musicaux), et de consulter le rail de diffusion dynamique.

### Authentification & Accès
- **Tous les endpoints** nécessitent une authentification via token Bearer Passport (`auth:api`).
- **En-têtes requis** :
  ```http
  Authorization: Bearer <access_token>
  Accept: application/json
  ```

---

## 2. Cycle de vie & Expiration

- **Durée de diffusion** : 24 heures à compter de la date de publication (`published_at`).
- **Calcul serveur** : L'expiration est strictement évaluée côté serveur via le champ `expires_at = published_at + 24 heures`.
- **Statuts d'une Story** :
  - `active` : Visible sur le rail et consultable par l'audience autorisée.
  - `expired` : Expirée sans archivage. Retourne `410 Gone` lors d'une tentative d'accès direct par un tiers.
  - `archived` : Expirée mais conservée dans les archives privées de son auteur si `allow_archive = true`.
  - `deleted` : Supprimée (soft-deleted).
- **Tâche planifiée** : Commande Artisan `php artisan stories:expire` exécutée toutes les 15 minutes pour basculer automatiquement les Stories expirées vers le statut `archived` ou `expired`.

---

## 3. Règles d'audience & Confidentialité

Chaque Story définit son audience :
1. `everyone` (Tout le monde) : Accessible à tous les utilisateurs de la plateforme, sauf restrictions de blocage réciproque (`user_blocks`).
2. `followers` (Abonnés) : Accessible uniquement aux utilisateurs abonnés au compte de l'auteur (`follows`).
3. `chosen` (Liste choisie / Proches) : Accessible uniquement aux utilisateurs enregistrés dans la liste choisie du créateur (`user_close_friends`).

---

## 4. Endpoints API — Stories (`/api/stories`)

### 4.1. Récupérer le rail des Stories actives
- **Méthode** : `GET /api/stories/rail`
- **Description** : Renvoie les profils des utilisateurs ayant des Stories actives accessibles. Ordonné avec l'utilisateur connecté en tête s'il a publié, suivi des profils ayant des Stories **non vues** (les plus récentes en premier), puis des profils dont toutes les Stories ont été consultées.
- **Réponse réussie (200 OK)** :
  ```json
  {
    "success": true,
    "data": [
      {
        "user": {
          "id": 12,
          "name": "Christian Paul",
          "handle": "cpaul",
          "display_name": "Christian P.",
          "avatar_url": "https://storage.bango.app/avatars/12.jpg",
          "is_verified": true,
          "is_mine": false
        },
        "stories_count": 3,
        "unviewed_count": 2,
        "has_unviewed": true,
        "latest_story_id": "9d1b092a-8742-4f36-a36c-dfc919d65742",
        "latest_published_at": "2026-10-15T14:30:00.000000Z",
        "latest_media_url": "https://storage.bango.app/stories/images/12/story_1.jpg",
        "latest_thumbnail_url": "https://storage.bango.app/stories/thumbnails/12/story_1_thumb.jpg",
        "audience": "everyone"
      }
    ]
  }
  ```

---

### 4.2. Créer et publier une Story
- **Méthode** : `POST /api/stories`
- **Content-Type** : `multipart/form-data`
- **Paramètres du payload** :
  | Champ | Type | Requis | Description |
  | --- | --- | --- | --- |
  | `file` ou `media` | File | Oui* | Fichier photo (max 10 Mo, JPG/PNG/WEBP) ou vidéo (max 50 Mo, MP4/MOV, durée max 30s). *Non requis si `slides` est fourni |
  | `audience` | String | Non | `everyone`, `followers`, `chosen` (défaut : `everyone`) |
  | `allow_replies` | Boolean | Non | Autoriser les réponses privées (défaut : `true`) |
  | `allow_archive` | Boolean | Non | Basculer en archive après 24h (défaut : `true`) |
  | `caption` | String | Non | Légende textuelle facultative (max 1000 caractères) |
  | `filter` | String | Non | Filtre visuel appliqué (ex: `vintage`, `noir_et_blanc`, `normal`) |
  | `duration` | Integer | Non | Durée d'affichage (en secondes, max 30s) |
  | `sound_id` | String | Non | Identifiant d'un son issu du catalogue des Reels |
  | `sound_start_at` | Integer | Non | Position de départ audio en secondes |
  | `stickers` | Array | Non | Liste des stickers créatifs |
  | `slides` | Array | Non | Diapositives multiples pour publication séquentielle |
- **Structure d'un sticker** :
  ```json
  {
    "type": "poll",
    "x": 0.5,
    "y": 0.65,
    "scale": 1.0,
    "rotation": 0.0,
    "data": {
      "question": "Votre plat préféré à Douala ?",
      "options": ["Ndolè", "Eru"]
    }
  }
  ```
- **Réponse réussie (201 Created)** : Renvoie l'objet `Story` complet avec ses diapositives et stickers.

---

### 4.3. Consulter une Story par son identifiant
- **Méthode** : `GET /api/stories/{id}`
- **Paramètre de route** : `id` (UUID de la Story)
- **Codes retours** :
  - `200 OK` : Story active accessible renvoyée avec ses diapositives.
  - `404 Not Found` : Story introuvable ou restreinte par l'audience / blocage.
  - `410 Gone` : La Story a dépassé les 24 heures de validité.

---

### 4.4. Récupérer les diapositives d'une Story
- **Méthode** : `GET /api/stories/{id}/slides`
- **Codes retours** : `200 OK` avec la collection des `StorySlideResource`.

---

### 4.5. Récupérer mes Stories actives
- **Méthode** : `GET /api/stories/mine`
- **Description** : Renvoie toutes les Stories en cours de diffusion de l'utilisateur connecté.

---

### 4.6. Récupérer mes Stories archivées
- **Méthode** : `GET /api/stories/archived?per_page=20`
- **Description** : Renvoie l'historique paginé des Stories expirées ayant activé l'archivage.

---

### 4.7. Consulter le détail de ma Story avec statistiques
- **Méthode** : `GET /api/stories/mine/{id}`
- **Description** : Réservé à l'auteur. Renvoie la Story avec le nombre de vues, les détails des votes de sondages et le compteur des réponses privées.

---

### 4.8. Consulter les spectateurs (Propriétaire uniquement)
- **Méthode** : `GET /api/stories/{id}/viewers?per_page=20`
- **Sécurité** : Retourne `403 Forbidden` si l'utilisateur appelant n'est pas le créateur de la Story.

---

### 4.9. Enregistrer une vue (Consultation)
- **Méthode** : `POST /api/stories/{id}/view`
- **Payload optionnel** :
  ```json
  {
    "slide_id": "optional-uuid-of-slide"
  }
  ```
- **Fonctionnement** :
  - Déduplication stricte (1 seule vue comptabilisée par utilisateur).
  - Anonymisation de l'adresse IP via hachage HMAC cryptographique `sha256(IP + APP_KEY)`.

---

### 4.10. Voter sur un sondage
- **Méthode** : `POST /api/stories/{slideId}/poll/{stickerId}/vote`
- **Payload** :
  ```json
  {
    "option_index": 0
  }
  ```
- **Codes retours** :
  - `200 OK` : Vote enregistré avec succès, renvoie les résultats actualisés.
  - `409 Conflict` : Double vote interdit (l'utilisateur a déjà voté).
  - `410 Gone` : La Story est expirée.

---

### 4.11. Enregistrer une réponse privée (Prête pour la Messagerie)
- **Méthode** : `POST /api/stories/{id}/reply`
- **Payload** :
  ```json
  {
    "type": "text",
    "text": "Super photo !",
    "idempotency_key": "optional-client-uuid",
    "slide_id": "optional-uuid"
  }
  ```
  Ou pour une réaction rapide :
  ```json
  {
    "type": "reaction",
    "reaction": "🔥"
  }
  ```
- **Codes retours** :
  - `201 Created` : Interaction enregistrée, statut `pending`.
  - `403 Forbidden` : Réponses désactivées par l'auteur ou blocage mutuel.
  - `410 Gone` : Story expirée.
  - `422 Unprocessable` : Réponse à soi-même refusée.

---

### 4.12. Gérer la Liste Choisie (Close Friends)
- **Consulter la liste** : `GET /api/stories/close-list` (200 OK)
- **Remplacer la liste atomiquement** : `PUT /api/stories/close-list`
  ```json
  {
    "user_ids": [14, 25, 42]
  }
  ```
  - Rejette les doublons et les comptes inexistants ou bloqués.
  - Exclut automatiquement l'utilisateur appelant.

---

### 4.13. Collections « À la une » (Highlights) (`/api/highlights`)
- **Lister les collections** : `GET /api/highlights?user_id=12` (ou mes collections si omis).
- **Créer une collection** : `POST /api/highlights`
  ```json
  {
    "title": "Vacances 2026",
    "story_ids": ["uuid-story-1", "uuid-story-2"],
    "cover_story_id": "uuid-story-1"
  }
  ```
  *Contrainte* : Le titre est strictement limité à 20 caractères.
- **Détail d'une collection** : `GET /api/highlights/{id}`
- **Supprimer une collection** : `DELETE /api/highlights/{id}` (supprime le conteneur sans effacer les Stories).
