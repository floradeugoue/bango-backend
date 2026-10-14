# RÉFÉRENCE COMPLÈTE DE L'API SQUARE (CR1) — BANGO

## 1. Vue d'Ensemble

Toutes les routes de l'API Square sont préfixées par `/api/v1` et nécessitent une authentification OAuth2 Bearer Token gérée par Laravel Passport (`auth:api`).

---

## 2. Publications (Posts)

### 2.1. Créer une publication
- **Méthode** : `POST`
- **URL** : `/api/posts`
- **Nom de route** : `api.v1.posts.store`
- **Authentification** : `auth:api` (Bearer Token)
- **Permissions** : Utilisateur actif
- **Idempotence** : Supportée via le champ `idempotency_key` (UUID v4)
- **Corps JSON** :
```json
{
  "body": "Découvrez notre nouveau complexe résidentiel à Bonapriso ! #Immo #Bango",
  "audience": "public",
  "comments_by": "everyone",
  "comments_closed": false,
  "allow_share": true,
  "hide_likes": false,
  "as_page_id": null,
  "idempotency_key": "c1f7b8a2-9214-4e96-a81d-6b5d9822a101",
  "place": {
    "label": "Bonapriso",
    "detail": "Quartier, Douala",
    "city": "Douala",
    "neighbourhood": "Bonapriso"
  },
  "media": [
    {
      "url": "https://storage.bango.cm/square/media_1.jpg",
      "type": "photo",
      "mime_type": "image/jpeg",
      "file_size": 345000,
      "width": 1080,
      "height": 1350,
      "display_order": 0,
      "edit_metadata": { "filter": "warm", "crop": "4:5" }
    }
  ],
  "tags": [
    {
      "account_id": 12,
      "x": 0.45,
      "y": 0.60,
      "media_index": 0
    }
  ]
}
```
- **Réponse Succès (201 Created ou 200 OK si idempotent)** :
```json
{
  "data": {
    "id": "1",
    "authorId": "5",
    "authorName": "Florian Deugoue",
    "authorAvatar": "https://.../avatar.jpg",
    "authorVerified": true,
    "authorBadge": "landlord",
    "authorMeta": "@florian_d",
    "body": "Découvrez notre nouveau complexe résidentiel à Bonapriso ! #Immo #Bango",
    "caption": "Découvrez notre nouveau complexe résidentiel à Bonapriso ! #Immo #Bango",
    "media": "https://storage.bango.cm/square/media_1.jpg",
    "mediaType": "photo",
    "photo": "https://storage.bango.cm/square/media_1.jpg",
    "photos": [
      "https://storage.bango.cm/square/media_1.jpg"
    ],
    "aspect": 0.8,
    "place": "Bonapriso",
    "place_details": {
      "label": "Bonapriso",
      "detail": "Quartier, Douala",
      "city": "Douala",
      "neighbourhood": "Bonapriso"
    },
    "likes": 0,
    "comments": 0,
    "shares": 0,
    "liked": false,
    "audience": "public",
    "commentsBy": "everyone",
    "commentsClosed": false,
    "shareable": true,
    "hideLikes": false,
    "mine": true,
    "edited": false,
    "is_archived": false,
    "at": "2026-10-14T14:40:00.000000Z",
    "tags": [
      {
        "accountId": "12",
        "handle": "partenaire_immo",
        "name": "Partenaire Immo",
        "x": 0.45,
        "y": 0.6,
        "mediaIndex": 0
      }
    ],
    "hashtags": [
      "Immo",
      "Bango"
    ]
  }
}
```
- **Erreurs Possibles** :
  - `401 Unauthorized` : Token manquant ou expiré.
  - `422 Unprocessable Entity` : Texte vide et aucun média, plus de 10 médias, ou champs invalides.

---

### 2.2. Consulter une publication
- **Méthode** : `GET`
- **URL** : `/api/posts/{id}`
- **Nom de route** : `api.v1.posts.show`
- **Contrôle d'accès** : Respecte les règles d'audience (`public`, `followers`, `neighbourhood`, non-archivé ou auteur).
- **Réponse Succès (200 OK)** : Structure `PostResource`.
- **Erreurs Possibles** :
  - `403 Forbidden` : Publication restreinte ou archivée par un autre utilisateur.
  - `404 Not Found` : ID introuvable ou supprimé.

---

### 2.3. Modifier la légende (SQ-15)
- **Méthode** : `PATCH`
- **URL** : `/api/posts/{id}/caption`
- **Nom de route** : `api.v1.posts.caption`
- **Permissions** : Auteur uniquement (`PostPolicy::update`).
- **Corps JSON** :
```json
{
  "caption": "Nouvelle légende mise à jour #BangoImmo"
}
```
- **Effets Secondaires** : Active le flag `is_edited = true`, resynchronise les hashtags dans la base.
- **Réponse Succès (200 OK)** : `PostResource` avec `edited: true`.

---

### 2.4. Modifier l'audience (SQ-15)
- **Méthode** : `PATCH`
- **URL** : `/api/posts/{id}/audience`
- **Nom de route** : `api.v1.posts.audience`
- **Corps JSON** :
```json
{
  "audience": "followers"
}
```
- **Valeurs Autorisées** : `public`, `followers`, `neighbourhood`.

---

### 2.5. Modifier le statut des commentaires (SQ-15)
- **Méthode** : `PATCH`
- **URL** : `/api/posts/{id}/comments-status`
- **Nom de route** : `api.v1.posts.comments_status`
- **Corps JSON** :
```json
{
  "comments_closed": true,
  "comments_by": "nobody"
}
```

---

### 2.6. Archiver et Désarchiver (SQ-15)
- **Archiver** : `POST /api/posts/{id}/archive`
  - Effet : masque le post du flux public tout en le conservant accessible à l'auteur.
- **Désarchiver** : `POST /api/posts/{id}/unarchive`
  - Effet : rétablit la visibilité publique du post.

---

### 2.7. Supprimer une publication (SQ-15)
- **Méthode** : `DELETE`
- **URL** : `/api/posts/{id}`
- **Nom de route** : `api.v1.posts.destroy`
- **Permissions** : Auteur uniquement.
- **Effet** : Soft-delete du post et décrémentation des compteurs des hashtags associés.
- **Réponse Succès (200 OK)** :
```json
{
  "success": true,
  "message": "Publication supprimée avec succès."
}
```

---

## 3. Fil d'Actualité Square (Feed)

### 3.1. Récupérer le fil
- **Méthode** : `GET`
- **URL** : `/api/square/feed`
- **Paramètres Query** :
  - `tab` : `'discover'` (défaut), `'following'`, `'neighbourhood'`.
  - `author_id` : ID d'un utilisateur pour voir ses publications sur son profil.
  - `archived` : `true` (uniquement si `author_id === current_user->id`).
  - `per_page` : Nombre par page (défaut: 15, max: 50).
- **Réponse (200 OK)** : Tableau paginé Laravel standard avec `data`, `links`, `meta`.

---

## 4. Téléversement de Médias (Media)

### 4.1. Upload d'un média
- **Méthode** : `POST`
- **URL** : `/api/media/upload`
- **Content-Type** : `multipart/form-data`
- **Champs Form** :
  - `file` : Fichier binaire (JPEG, PNG, WEBP max 15MB, MP4, MOV max 100MB).
  - `kind` : `'photo'` ou `'video'`.
- **Réponse (201 Created)** :
```json
{
  "success": true,
  "data": {
    "id": "uuid-v4",
    "url": "https://storage.bango.cm/storage/square/5/media_uuid.jpg",
    "type": "photo",
    "mime_type": "image/jpeg",
    "file_size": 450120,
    "width": 1080,
    "height": 1350,
    "duration": null
  }
}
```

---

## 5. Recherche Contextuelle (Search)

- `GET /api/search/people?q={terme}` : Recherche insensible à la casse sur pseudo, nom et display_name.
- `GET /api/search/hashtags/trending?q={terme}` : Liste des hashtags tendances ordonnés par `posts_count` desc.
- `GET /api/search/places?q={terme}` : Recherche sur les quartiers et villes de la base BANGO.
