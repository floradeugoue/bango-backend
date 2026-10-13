# BANGO — Spécification de l'API Module PR (Profil)

Ce document détaille l'intégralité des contrats d'API du module **PR (Profil)** sur le backend BANGO.

---

## 🔑 Conventions & En-têtes

Toutes les requêtes de l'API utilisent :
```http
Accept: application/json
Content-Type: application/json
```
Pour les routes authentifiées :
```http
Authorization: Bearer <access_token>
```
Pour l'upload de photo de profil (Avatar) :
```http
Content-Type: multipart/form-data
```
*(Envoyé en méthode `POST` avec le champ `_method=PUT` conformément au comportement PHP/Laravel).*

---

## 📌 1. Profil Personnel (Self Profile)

### `GET /api/profile`
Consulter les données complètes de son profil.
- **Authentification** : Requise (`auth:api`)
- **Réponse Succès (200 OK)** :
```json
{
  "data": {
    "id": 1,
    "email": "flora@example.com",
    "phone": "+237699001122",
    "handle": "flora.d",
    "displayName": "Flora Deugoue",
    "avatarUrl": "http://localhost:8000/storage/avatars/avatar_1.jpg",
    "bio": "Passionnée d'immobilier et de tech urbaine.",
    "birthDate": "2000-05-15",
    "countryCode": "CM",
    "countryId": 1,
    "city": "Douala",
    "cityId": 1,
    "neighbourhood": "Akwa",
    "neighbourhoodId": 2,
    "locale": "fr",
    "isVerified": true,
    "accountType": "personal",
    "gender": "woman",
    "genderHidden": false,
    "isPrivate": false,
    "badge": "landlord",
    "verificationLapsed": false,
    "restrictedUntil": null,
    "counts": {
      "followers": 12,
      "following": 5,
      "posts": 0
    },
    "privacySettings": {
      "privateAccount": false,
      "seePosts": "everyone",
      "commentPosts": "followers",
      "messageMe": "everyone",
      "tagMe": "followers",
      "onlineStatus": true,
      "readReceipts": true
    },
    "onboardingProgress": {
      "completed": ["credentials", "identity", "phone", "interests"],
      "updatedAt": "2026-10-13T12:00:00Z"
    },
    "interests": ["realestate", "tech", "food"]
  }
}
```

---

### `POST /api/profile/handle-check`
Vérifier la disponibilité d'un pseudonyme en temps réel (frappe à l'écran AU-15 / PR-03).
- **Body** :
```json
{
  "handle": "flora.d",
  "city": "Douala"
}
```
- **Réponses Possibles (200 OK)** :
  - `{"state": "free"}`
  - `{"state": "taken", "since": "2024", "suggestions": ["flora.d24", "flora.douala"]}`
  - `{"state": "too-short"}` ($< 3$ car.)
  - `{"state": "too-long"}` ($> 20$ car.)
  - `{"state": "bad-shape"}` (caractères non conformes à `^[a-z0-9.]+$`)
  - `{"state": "reserved"}` (`admin`, `bango`, `support`, etc.)

---

### `PUT /api/profile/identity`
Mettre à jour le pseudonyme, le nom affiché et la biographie.
- **Body** :
```json
{
  "handle": "flora.d",
  "displayName": "Flora Deugoue",
  "bio": "Investisseur immobilier indépendant."
}
```
- **Règles de validation** :
  - `handle` : Obligatoire, 3 à 20 caractères, `^[a-z0-9.]+$`, unique dans la table `users` (hors l'utilisateur actuel).
  - `displayName` : Nullable, string max 50 caractères.
  - `bio` : Optionnel, string max 500 caractères (l'écran PR-03 borne à 150).
- **Réponse Succès (200 OK)** : `{"success": true}`
- **Erreurs** :
  - `422 Unprocessable Content` : `{ "errors": { "handle": ["The handle has already been taken."] } }`

---

### `PUT /api/profile/bio`
Mettre à jour uniquement la présentation.
- **Body** : `{"bio": "Ma nouvelle présentation..."}`
- **Réponse (200 OK)** : `{"success": true}`

---

### `PUT /api/profile/avatar`
Remplacer la photo de profil.
- **Format** : `multipart/form-data`
- **Champs** :
  - `avatar` : Fichier image (jpeg, png, webp, gif — max 5120 Ko).
  - `_method` : `PUT` (permet à PHP de lire les fichiers en FormData).
- **Comportement backend** : Supprime l'ancien avatar du stockage et persiste le nouveau dans `/storage/avatars/`.
- **Réponse (200 OK)** :
```json
{
  "avatarUrl": "http://localhost:8000/storage/avatars/avatar_670b2e8.jpg"
}
```

---

### `PUT /api/profile/birthdate`
Enregistrer la date de naissance.
- **Body** : `{"birthDate": "2000-05-15"}`
- **Règle** : L'utilisateur doit avoir au moins 18 ans révolus (`before_or_equal: 18 ans`).
- **Erreur (422)** si âge $< 18$ ans.

---

### `PUT /api/profile/gender`
Enregistrer le genre et le choix de masquage public (AU-07).
- **Body** :
```json
{
  "gender": "woman",
  "hidden": false
}
```
- **Valeurs valides** : `woman`, `man`, `other`, `undisclosed`.

---

### `PUT /api/profile/location`
Enregistrer la localisation (pays, ville, quartier).
- **Body** :
```json
{
  "countryCode": "CM",
  "cityId": 1,
  "neighbourhoodId": 2
}
```
- **Comportement** : Met à jour les clés étrangères et synchronise les libellés dénormalisés.

---

### `PUT /api/profile/interests`
Enregistrer les centres d'intérêt sélectionnés (AU-17).
- **Body** :
```json
{
  "interests": ["realestate", "tech", "food"]
}
```
- **Règle** : Minimum 3 identifiants valides de la table `interests`.

---

## 👥 2. Profil Public d'un Autre Compte (PR-07 / PR-08 / PR-09)

### `GET /api/profile/public/{identifier}`
*(Également accessible via `GET /api/profiles/{identifier}` et `GET /api/profile/user/{identifier}`)*

Permet de charger la fiche profil d'un autre utilisateur en passant soit son **ID numérique** (ex: `42`), soit son **pseudonyme** (ex: `flora.d` ou `@flora.d`).

- **Réponse Succès (200 OK)** :
```json
{
  "data": {
    "id": 2,
    "name": "Jean Elessa",
    "handle": "jean.e",
    "avatarUrl": "http://localhost:8000/storage/avatars/jean.jpg",
    "place": "Bonapriso, Douala",
    "countryCode": "CM",
    "city": "Douala",
    "neighbourhood": "Bonapriso",
    "bio": "Agent immobilier certifié chez Bango Immo.",
    "badge": "agent",
    "badges": ["Agent"],
    "verified": true,
    "verificationLapsed": false,
    "private": false,
    "tone": "warm",
    "counts": {
      "followers": 128,
      "following": 45,
      "posts": 0
    },
    "isFollowing": false,
    "isFollowedBy": true,
    "hasRequested": false,
    "isBlocked": false,
    "isBlockedBy": false,
    "canViewContent": true
  }
}
```

- **États Spécifiques** :
  - **Compte privé non suivi (PR-08)** : `private: true`, `isFollowing: false`, `canViewContent: false`. Les publications et listings ne s'affichent pas, un bouton « Demander à suivre » est présenté.
  - **Demande envoyée en attente** : `hasRequested: true`. Le bouton affiche « Demandé ».
  - **Compte bloqué (PR-09)** : `isBlocked: true`, `canViewContent: false`.
  - **Compte introuvable ou supprimé (PR-10)** : HTTP `404 Not Found` `{ "kind": "not-found", "message": "Ce compte n'existe plus." }`.
  - **Compte suspendu par la modération** : HTTP `403 Forbidden` `{ "kind": "suspended" }`.

---

## 🤝 3. Relations Sociales (Abonnements & Demandes)

### `POST /api/profile/{id}/follow`
Suivre un utilisateur ou envoyer une demande d'abonnement.
- **Comportement** :
  - Si le compte cible est **public** : abonnement immédiat $\rightarrow$ Réponse : `{"status": "followed"}`.
  - Si le compte cible est **privé** : création d'une demande d'abonnement en attente $\rightarrow$ Réponse : `{"status": "requested"}`.
- **Règles de sécurité** :
  - Auto-suivi interdit $\rightarrow$ `422 Unprocessable Content`.
  - Utilisateur bloqué (dans un sens ou dans l'autre) $\rightarrow$ `403 Forbidden`.

---

### `DELETE /api/profile/{id}/follow` (ou `POST /api/profile/{id}/unfollow`)
Ne plus suivre un utilisateur, ou retirer une demande envoyée en attente.
- **Réponse (200 OK)** : `{"status": "unfollowed"}` ou `{"status": "withdrawn"}`.

---

### `POST /api/profile/{id}/toggle-follow`
Bascule l'état de suivi (suivre $\leftrightarrow$ ne plus suivre) en un seul geste.
- **Réponse (200 OK)** : `{"status": "followed"|"unfollowed"|"requested"|"withdrawn"}`.

---

### `GET /api/profile/followers` & `GET /api/profile/{id}/followers`
Liste paginée des abonnés.
- **Paramètres de requête** :
  - `query` : Recherche filtrant sur le nom ou le pseudonyme.
  - `per_page` : Nombre d'éléments par page (défaut : 20).
  - `page` : Numéro de page.
- **Réponse (200 OK)** :
```json
{
  "data": [
    {
      "id": 5,
      "name": "Marc Mbarga",
      "handle": "marc.m",
      "avatarUrl": "http://...",
      "badge": "none",
      "verified": false,
      "place": "Akwa, Douala",
      "bio": "Locataire en recherche",
      "isFollowing": true,
      "hasRequested": false
    }
  ],
  "links": { ... },
  "meta": { ... }
}
```

---

### `GET /api/profile/following` & `GET /api/profile/{id}/following`
Liste paginée des comptes suivis.
- Structure identique à la liste des abonnés.

---

### `GET /api/profile/follow-requests`
Consulter les demandes d'abonnement reçues par l'utilisateur connecté (pour compte privé).
- **Réponse (200 OK)** : Liste des utilisateurs demandeurs avec `FollowUserResource`.

---

### `POST /api/profile/follow-requests/{id}/accept`
Accepter une demande d'abonnement reçue.
- **Comportement** : Enregistre l'abonnement du demandeur vers l'utilisateur connecté et supprime la demande.
- **Réponse (200 OK)** : `{"success": true}`.

---

### `POST /api/profile/follow-requests/{id}/reject`
Refuser discrètement une demande d'abonnement.
- **Comportement** : Supprime la demande sans notifier l'expéditeur.
- **Réponse (200 OK)** : `{"success": true}`.

---

### `POST /api/profile/follow-requests/accept-all`
Accepter d'un coup toutes les demandes d'abonnement en attente.
- **Réponse (200 OK)** : `{"success": true, "acceptedCount": 4}`.

---

## 🚫 4. Blocage d'Utilisateurs (PR-09 / SUP-15)

### `GET /api/profile/blocked`
Lister tous les comptes actuellement bloqués par l'utilisateur connecté.
- **Réponse (200 OK)** : Liste paginée des comptes bloqués.

---

### `POST /api/profile/{id}/block`
Bloquer un utilisateur.
- **Comportements de sécurité** :
  - Empêche l'auto-blocage (422).
  - Rompt immédiatement les abonnements dans les deux sens (`following` et `followers`).
  - Supprime toutes les demandes d'abonnements en attente entre les deux comptes.
  - Enregistre le blocage dans la table `user_blocks`.
- **Réponse (200 OK)** : `{"success": true, "blocked": true}`.

---

### `DELETE /api/profile/{id}/block` (ou `POST /api/profile/{id}/unblock`)
Débloquer un utilisateur.
- **Réponse (200 OK)** : `{"success": true, "blocked": false}`.

---

## 🔒 5. Confidentialité (ST-14)

### `GET /api/profile/privacy` & `GET /api/settings/privacy`
Lire ses préférences de confidentialité.
- **Réponse (200 OK)** :
```json
{
  "privateAccount": false,
  "seePosts": "everyone",
  "commentPosts": "followers",
  "messageMe": "everyone",
  "tagMe": "followers",
  "onlineStatus": true,
  "readReceipts": true
}
```

---

### `PUT /api/profile/privacy` & `PUT /api/settings/privacy`
Mettre à jour ses préférences de confidentialité.
- **Body** :
```json
{
  "privateAccount": false,
  "seePosts": "everyone",
  "commentPosts": "followers"
}
```
- **Règle métier automatique** : Si `privateAccount` passe de `true` à `false` (repassage en public), le backend accepte automatiquement toutes les demandes d'abonnement en attente pour en faire des abonnés.
- **Réponse (200 OK)** : Objet des préférences à jour.

---

## 📷 6. QR Code (PR-16 / PR-17)

### `POST /api/qr/resolve` (et `GET /api/qr/resolve?data=...`)
Résoudre un QR code ou un lien profond de profil scanné.
- **Entrées acceptées** :
  - Lien BANGO : `"bango://u/flora.d"`
  - Lien Web : `"https://bango.app/@flora.d"`
  - Pseudonyme brut : `"@flora.d"` ou `"flora.d"`
- **Réponse Succès (200 OK)** :
```json
{
  "id": 1,
  "handle": "flora.d",
  "displayName": "Flora Deugoue",
  "avatarUrl": "http://localhost:8000/storage/avatars/avatar_1.jpg",
  "badge": "landlord",
  "verified": true,
  "profileLink": "bango://u/flora.d"
}
```
- **Erreurs** :
  - Compte inexistant ou QR invalide : HTTP `404 Not Found` `{"kind": "not-found", "message": "Ce compte n'existe plus ou le QR code est invalide."}`.
  - Compte suspendu ou bloqué : HTTP `403 Forbidden`.
