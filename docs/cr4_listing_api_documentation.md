# Documentation API — Module CR4 (Cœur Immobilier) & Module IM (Consultation & Recherche)

Ce document formalise l'ensemble des contrats d'interface, schémas de requêtes, structures de réponses, codes d'erreurs et règles métier du module immobilier de BANGO.

---

## 1. Vue d'ensemble architecturale

Le module immobilier est composé de deux briques complémentaires :
- **CR4 (Cœur Immobilier)** : création d'annonces, gestion multi-étapes des brouillons, modération, téléversement de médias ordonnés avec qualification de pièces, visite guidée vidéo (format 9:16 portrait), cycle de vie et transitions d'état.
- **IM (Consultation, Recherche & Favoris)** : feed vertical paginé, fiche détaillée avec ventilation des frais d'entrée (caution, avance, charges, frais de visite), recherche géographique par coordonnées bounding-box avec clustering adaptatif, filtres avancés (prix, mode, chambres, meublé, forage, compteur individuel), compteur dynamique synchronisé et gestion des favoris.

---

## 2. Authentification et Niveaux d'accès

| Niveau d'accès | Middleware | Description |
|---|---|---|
| **Public** | Aucun | Accessible sans authentification (visiteurs et utilisateurs connectés). Les annonces non publiées (`draft`, `review`, `rejected`, soft-deleted) et les utilisateurs bloqués sont strictement masqués. |
| **Authentifié** | `auth:api` | Nécessite un jeton Passport actif (`Authorization: Bearer <token>`). Requis pour les brouillons, sauvegardes et consultations privées. |
| **Bailleur / Agent Certifié** | `auth:api` + vérification badge/rôle | Vérification stricte côté serveur que l'utilisateur possède un badge actif (`landlord`, `agent`, `double`) ou une souscription active non suspendue. Obligatoire pour publier (`POST /api/listings`). |
| **Gestionnaire / Propriétaire** | `auth:api` + Policy | Modification (`PATCH`), suppression (`DELETE`) ou ajout de médias (`POST /api/listings/{id}/media`) restreint au propriétaire légitime (`owner_id`) ou à l'agent mandaté (`agent_id`). |

---

## 3. Liste des Endpoints

### 3.1. Consultation & Recherche (Module IM)

#### `GET /api/listings`
- **Description** : Feed public d'annonces immobilières paginées pour consultation verticale.
- **Accès** : Public (personnalisé si authentifié avec exclusion des bloqués et indicateur `is_saved`).
- **Paramètres de requête (Query Params)** :
  - `page` *(int, def: 1)* : Numéro de page.
  - `per_page` *(int, def: 15, max: 50)* : Éléments par page.
  - `q` *(string)* : Recherche textuelle (titre, description, ville, quartier).
  - `mode` *(string)* : `rent`, `sale`, `flatshare`.
  - `kind` *(string)* : `studio`, `flat`, `villa`, `room`, `office`, `land`.
  - `city` *(string)* : Nom de ville (ex: `Douala`, `Yaoundé`, `Dakar`).
  - `neighbourhood` *(string)* : Nom de quartier (ex: `Akwa`, `Bonapriso`, `Almadies`).
  - `min_price` *(numeric)* : Prix plancher.
  - `max_price` *(numeric)* : Prix plafond.
  - `bedrooms` *(int|string|array)* : Nombre de chambres exact ou seuil plus (ex: `2`, `4+`, `[1, 2]`).
  - `showers` *(int)* : Nombre minimum de salles de bain/douches.
  - `furnished` *(bool: 0|1)* : Filtre meublé uniquement.
  - `individual_meter` *(bool: 0|1)* : Compteur électrique individuel.
  - `water_borehole` *(bool: 0|1)* : Présence de forage d'eau.
  - `verified_only` *(bool: 0|1)* : Bailleurs ou agents vérifiés uniquement.
- **Réponse 200 OK** :
```json
{
  "data": [
    {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "mode": "rent",
      "kind": "flat",
      "status": "published",
      "title": "Superbe Appartement Bonanjo",
      "price": 250000,
      "currency": "XOF",
      "period": "month",
      "negotiable": false,
      "utilities_included": false,
      "bedrooms": 2,
      "showers": 1,
      "area": 85.5,
      "attributes": ["2 chambres", "1 douche", "86 m²", "Meublé"],
      "location": "Près du consulat de France",
      "city": "Douala",
      "neighbourhood": "Bonanjo",
      "lat": 4.043512,
      "lng": 9.691244,
      "photos": [
        "https://bango.test/storage/listings/photos/photo_1.webp",
        "https://bango.test/storage/listings/photos/photo_2.webp"
      ],
      "photo_rooms": ["living", "bedroom"],
      "tour_url": "https://bango.test/storage/listings/videos/tour_1.mp4",
      "tour_duration": "1:45",
      "agent_visited": true,
      "charges": 15000,
      "published_at": "2026-10-15T18:00:00.000000Z",
      "owner": {
        "id": 12,
        "name": "Jean Dupont",
        "display_name": "Jean Dupont",
        "avatar_url": "https://bango.test/storage/avatars/user_12.jpg",
        "is_verified": true,
        "badge": "landlord",
        "role": "landlord",
        "meta": "Bailleur vérifié · répond en 2 h"
      },
      "saves": 38,
      "is_saved": false,
      "visit_fee": 10000,
      "sponsored": false,
      "media_count": 3
    }
  ],
  "links": {
    "first": "https://bango.test/api/listings?page=1",
    "last": "https://bango.test/api/listings?page=4",
    "prev": null,
    "next": "https://bango.test/api/listings?page=2"
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 4,
    "per_page": 15,
    "to": 15,
    "total": 52
  }
}
```

---

#### `GET /api/listings/count`
- **Description** : Compteur dynamique ultra-rapide des biens correspondant aux filtres actifs de l'application (IM-03).
- **Accès** : Public.
- **Paramètres de requête** : Mêmes paramètres de filtrage que `GET /api/listings`.
- **Réponse 200 OK** :
```json
{
  "success": true,
  "count": 52,
  "total": 52
}
```

---

#### `GET /api/listings/map`
- **Description** : Recherche géographique par emprise cartographique (bounding box) avec clustering adaptatif multi-résolution.
- **Accès** : Public.
- **Paramètres de requête** :
  - `north` *(float, -90 à 90)* : Latitude nord maximale.
  - `south` *(float, -90 à 90)* : Latitude sud minimale.
  - `east` *(float, -180 à 180)* : Longitude est maximale.
  - `west` *(float, -180 à 180)* : Longitude ouest minimale.
  - `zoom` *(int, 1 à 21, def: 14)* : Niveau de zoom cartographique.
  - Filtres métier complémentaires (`mode`, `min_price`, `max_price`, `bedrooms`, etc.).
- **Réponse 200 OK** :
```json
{
  "success": true,
  "data": {
    "pins": [
      {
        "id": "550e8400-e29b-41d4-a716-446655440000",
        "lat": 4.043512,
        "lng": 9.691244,
        "label": "250 k",
        "variant": "price",
        "listing": {
          "id": "550e8400-e29b-41d4-a716-446655440000",
          "title": "Superbe Appartement Bonanjo",
          "price": 250000,
          "currency": "XOF",
          "mode": "rent",
          "city": "Douala",
          "neighbourhood": "Bonanjo",
          "primary_photo": "https://bango.test/storage/listings/photos/photo_1.webp"
        }
      },
      {
        "id": "cluster_193_94",
        "lat": 4.051200,
        "lng": 9.704500,
        "label": "6",
        "variant": "cluster",
        "count": 6
      }
    ],
    "clusters": [
      {
        "id": "cluster_193_94",
        "lat": 4.051200,
        "lng": 9.704500,
        "label": "6",
        "variant": "cluster",
        "count": 6
      }
    ],
    "total": 7
  }
}
```

---

#### `GET /api/listings/{id}`
- **Description** : Fiche détaillée complète d'une annonce immobilière (IM-05, CR-45). Incrémente automatiquement le compteur de vues pour les visiteurs tiers.
- **Accès** : Public si statut `published` ou `reserved`. Restreint au propriétaire/gestionnaire pour `draft`, `review` ou `taken`.
- **Réponse 200 OK** :
```json
{
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "mode": "rent",
    "kind": "flat",
    "status": "published",
    "title": "Superbe Appartement Bonanjo",
    "price": 250000,
    "currency": "XOF",
    "period": "month",
    "negotiable": false,
    "utilities_included": false,
    "bedrooms": 2,
    "showers": 1,
    "area": 85.5,
    "attributes": ["2 chambres", "1 douche", "86 m²", "Meublé"],
    "location": "Près du consulat de France",
    "city": "Douala",
    "neighbourhood": "Bonanjo",
    "lat": 4.043512,
    "lng": 9.691244,
    "photos": [
      "https://bango.test/storage/listings/photos/photo_1.webp",
      "https://bango.test/storage/listings/photos/photo_2.webp"
    ],
    "photo_rooms": ["living", "bedroom"],
    "tour_url": "https://bango.test/storage/listings/videos/tour_1.mp4",
    "tour_duration": "1:45",
    "agent_visited": true,
    "charges": 15000,
    "published_at": "2026-10-15T18:00:00.000000Z",
    "owner": {
      "id": 12,
      "name": "Jean Dupont",
      "display_name": "Jean Dupont",
      "avatar_url": "https://bango.test/storage/avatars/user_12.jpg",
      "is_verified": true,
      "badge": "landlord",
      "role": "landlord",
      "meta": "Bailleur vérifié · répond en 2 h"
    },
    "saves": 38,
    "is_saved": false,
    "visit_fee": 10000,
    "sponsored": false,
    "media_count": 3,
    "description": "Grand appartement rénové avec cuisine équipée et groupe électrogène.",
    "amenities": ["furnished", "meter", "borehole", "parking"],
    "features": [
      {"id": "meter", "label": "Compteur individuel"},
      {"id": "water", "label": "Forage permanent"}
    ],
    "deposit_months": 2,
    "advance_months": 1,
    "move_in_costs": [
      {
        "id": "deposit",
        "label": "Caution",
        "amount": 500000,
        "months": 2
      },
      {
        "id": "advance",
        "label": "Loyer d'avance",
        "amount": 250000,
        "months": 1
      },
      {
        "id": "visit",
        "label": "Frais de réservation de visite",
        "amount": 10000
      },
      {
        "id": "charges",
        "label": "Charges d'immeuble mensuelles",
        "amount": 15000,
        "perMonth": true
      }
    ],
    "upfront_total": 760000,
    "monthly_total": 265000,
    "address": "Rue des Cocotiers, Bonanjo",
    "all_media": [
      {
        "id": "770e8400-e29b-41d4-a716-446655440001",
        "type": "image",
        "url": "https://bango.test/storage/listings/photos/photo_1.webp",
        "thumbnail_url": null,
        "room": "living",
        "order": 1,
        "width": 1920,
        "height": 1080,
        "size": 450120
      }
    ]
  }
}
```

---

### 3.2. Gestion des Favoris (IM-16)

#### `POST /api/listings/{id}/save`
- **Description** : Enregistrement idempotent ou bascule d'une annonce dans les favoris.
- **Accès** : Authentifié (`auth:api`).
- **Body JSON** :
```json
{
  "action": "save" // Options: "save" (défaut, idempotent), "unsave", "toggle"
}
```
- **Réponse 200 OK** :
```json
{
  "success": true,
  "saved": true,
  "saves_count": 39,
  "message": "Annonce ajoutée à vos favoris."
}
```

#### `DELETE /api/listings/{id}/save`
- **Description** : Retrait explicite d'une annonce des favoris de l'utilisateur.
- **Accès** : Authentifié (`auth:api`).
- **Réponse 200 OK** :
```json
{
  "success": true,
  "saved": false,
  "saves_count": 38,
  "message": "Annonce retirée de vos favoris."
}
```

#### `GET /api/listings/saved`
- **Description** : Liste paginée des annonces sauvegardées par l'utilisateur connecté. Les annonces supprimées ou repassées en brouillon sont automatiquement masquées.
- **Accès** : Authentifié (`auth:api`).
- **Réponse 200 OK** : Structure paginée identique à `GET /api/listings`.

---

### 3.3. Création & Brouillons (Module CR4)

#### `GET /api/listings/form/draft`
- **Description** : Consultation ou reprise du brouillon actif pour le compte connecté.
- **Accès** : Authentifié (`auth:api`).
- **Query Params** :
  - `submission_key` ou `key` *(UUID, optionnel)* : Clé spécifique du brouillon.
- **Réponse 200 OK** : Retourne `{ "data": null }` si aucun brouillon actif n'existe, ou la ressource détaillée du brouillon s'il existe.

---

#### `POST /api/listings/form/draft`
- **Description** : Sauvegarde partielle ou complète d'un brouillon à n'importe quelle étape du formulaire (CR-40 à CR-48). Ne publie jamais l'annonce.
- **Accès** : Authentifié (`auth:api`).
- **Body JSON** :
```json
{
  "submission_key": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "step": 3,
  "form_data": {
    "title": "Grand F3 moderne",
    "mode": "rent",
    "kind": "flat",
    "price": 200000,
    "city": "Douala",
    "neighbourhood": "Makepe",
    "bedrooms": 2,
    "deposit_months": 2
  }
}
```
- **Réponse 200 OK** : Retourne l'objet brouillon mis à jour avec son `submission_key` et son `draft_step`.

---

#### `DELETE /api/listings/form/draft`
- **Description** : Suppression d'un brouillon d'annonce actif.
- **Accès** : Authentifié (`auth:api`).

---

#### `POST /api/listings`
- **Description** : Création et soumission d'une annonce immobilière pour modération ou publication directe.
- **Accès** : Authentifié + Badge vérifié (`landlord`, `agent`, `double` ou admin).
- **Body JSON** :
```json
{
  "submission_key": "3fa85f64-5717-4562-b3fc-2c963f66afa6",
  "title": "Superbe Appartement Bonanjo",
  "description": "Appartement calme et sécurisé avec groupe électrogène.",
  "mode": "rent",
  "kind": "flat",
  "price": 250000,
  "currency": "XOF",
  "period": "month",
  "deposit_months": 2,
  "advance_months": 1,
  "charges": 15000,
  "visit_fee": 10000,
  "negotiable": false,
  "utilities_included": false,
  "bedrooms": 2,
  "showers": 1,
  "area": 85.5,
  "city": "Douala",
  "neighbourhood": "Bonanjo",
  "address": "Rue des Cocotiers",
  "location": "Près du consulat de France",
  "lat": 4.043512,
  "lng": 9.691244,
  "amenities": ["furnished", "meter", "borehole", "parking"],
  "features": [
    {"id": "meter", "label": "Compteur individuel"},
    {"id": "water", "label": "Forage permanent"}
  ],
  "agent_visited": false
}
```
- **Réponse 201 Created** : Retourne l'annonce créée avec le statut `review` (soumise en modération) ou `published` si autorisé.

---

#### `PATCH /api/listings/{id}`
- **Description** : Mise à jour granulaire des caractéristiques physiques ou financières d'un bien existant (CR-48).
- **Accès** : Propriétaire ou Agent mandaté.
- **Body JSON** : N'importe quel sous-ensemble de champs modifiables (`title`, `price`, `description`, `bedrooms`, `amenities`, etc.).

---

#### `PATCH /api/listings/{id}/status`
- **Description** : Transition de l'état d'un bien (BL-18).
- **Accès** : Propriétaire ou Agent mandaté.
- **Body JSON** :
```json
{
  "status": "reserved", // Valeurs permises pour le bailleur : "published", "reserved", "taken"
  "reason": null
}
```
- **Réponse 200 OK** : Annonce mise à jour avec le nouveau statut.

---

#### `DELETE /api/listings/{id}`
- **Description** : Suppression (soft delete) d'une annonce.
- **Accès** : Propriétaire ou Agent mandaté.
- **Réponse 200 OK** : `{ "success": true, "message": "Annonce retirée avec succès." }`.

---

### 3.4. Gestion des Médias (CR-41, CR-42, CR-43)

#### `POST /api/listings/media/upload-temp`
- **Description** : Téléversement temporaire sécurisé d'un fichier média (photo ou vidéo) pendant le flux du formulaire brouillon.
- **Accès** : Authentifié (`auth:api`).
- **Body Form-Data** :
  - `file` *(File)* : Image (JPEG/PNG/WEBP, max 10 Mo) ou Vidéo (MP4/MOV, 30s-180s, max 100 Mo).
  - `type` *(string)* : `image` ou `video`.
  - `room` *(string, optionnel pour photo)* : `living`, `bedroom`, `kitchen`, `shower`, `outside`.
- **Réponse 201 Created** :
```json
{
  "success": true,
  "data": {
    "path": "listings/temp/tmp_file_abc123.webp",
    "url": "https://bango.test/storage/listings/temp/tmp_file_abc123.webp",
    "type": "image",
    "room": "living",
    "size": 450120
  }
}
```

---

#### `POST /api/listings/{id}/media`
- **Description** : Attachement direct d'un fichier média à une annonce existante.
- **Accès** : Propriétaire ou Agent mandaté.
- **Body Form-Data** : `file`, `type`, `room`.
- **Réponse 201 Created** : Objet `ListingMediaResource`.

---

#### `POST /api/listings/{id}/media/reorder`
- **Description** : Réorganisation stable de l'ordre d'affichage des photos.
- **Accès** : Propriétaire ou Agent mandaté.
- **Body JSON** :
```json
{
  "order": [
    "770e8400-e29b-41d4-a716-446655440002",
    "770e8400-e29b-41d4-a716-446655440001",
    "770e8400-e29b-41d4-a716-446655440003"
  ]
}
```
- **Réponse 200 OK** : `{ "success": true, "message": "Ordre des médias mis à jour." }`.

---

#### `DELETE /api/listings/{id}/media/{media_id}`
- **Description** : Suppression physique sécurisée du fichier disque et de l'enregistrement média.
- **Accès** : Propriétaire ou Agent mandaté.
- **Réponse 200 OK** : `{ "success": true, "message": "Média supprimé avec succès." }`.

---

## 4. Règles de calcul des frais d'entrée (`move_in_costs`)

Le backend calcule de façon déterministe et sans nombres flottants les frais d'entrée à l'aide de `ListingPricingService` :
1. **Caution (`deposit`)** : `price * deposit_months`.
2. **Avance de loyer (`advance`)** : `price * advance_months`.
3. **Frais de visite / réservation (`visit`)** : montant fixe stipulé par le bailleur/gestionnaire (ex: 10 000 XOF).
4. **Charges d'immeuble mensuelles (`charges`)** : charges courantes par mois (gardiennage, eau commune, etc.).
5. **Total à l'entrée (`upfront_total`)** : `Caution + Avance + Frais de réservation`.
6. **Total mensuel récurrent (`monthly_total`)** : `Loyer + Charges`.
