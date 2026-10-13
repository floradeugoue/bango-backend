# BANGO - Guide Complet d'Intégration Frontend & Mobile

Bienvenue sur le backend de **BANGO** ! Ce document constitue la référence exhaustive pour les développeurs frontend (Application Mobile Flutter/React Native et Dashboard Administrateur Web). Il détaille l'ensemble des routes, headers, payloads JSON, flux d'authentification, règles métier et codes d'erreur.

---

## 📑 Sommaire
1. [Configuration Générale & Headers](#-configuration-générale--headers)
2. [Authentification & Rôles Système](#-authentification--rôles-système)
3. [Format Universel du Profil (`UserResource`)](#-format-universel-du-profil-userresource)
4. [Endpoints Publics](#-1-endpoints-publics-aucun-token-requis)
   - [Authentification, Inscription & 2FA](#-authentification-inscription--2fa-apiauth)
   - [Gestion & État de Compte (AU-26 / AU-27)](#-état-et-restauration-de-compte-apiauth)
   - [Réinitialisation de Mot de Passe](#-réinitialisation-de-mot-de-passe)
   - [Référentiel des Centres d'intérêt](#-centres-dintérêt-apiinterests)
   - [Référentiel Géographique (Lecture Publique)](#-référentiel-géographique-lecture-publique-apigeo)
5. [Endpoints Utilisateur Connecté (Mobile)](#-2-endpoints-utilisateur-connecté-token-requis)
   - [Session & Profil](#-profil-utilisateur-apiprofile)
   - [Vérification & OTP (AU-12)](#-codes-otp-apiotp)
   - [Onboarding](#-onboarding-apionboarding)
   - [Recherche de Logement](#-recherche-de-logement-apihousingsearch)
   - [Paramètres, Sécurité & Sessions](#-paramètres--sécurité-apisettings)
6. [Endpoints Back-Office Administrateur](#-3-endpoints-administrateur-token--rôle-admin)
   - [Gestion des Rôles Système](#-rôles-système-apiroles)
   - [Modération des Utilisateurs & Sessions](#-modération-utilisateurs--sessions-apiusers)
   - [Administration du Référentiel Géo](#-gestion-géographique-admin-apigeo)
7. [Tableau Exhaustif des Réponses d'Erreurs BANGO](#-tableau-exhaustif-des-erreurs-bango)

---

## ⚙️ Configuration Générale & Headers

- **Base URL locale** : `http://localhost:8000` (ou IP locale du serveur ex: `http://192.168.1.50:8000`)
- **Documentation Interactive Swagger / OpenAPI** :
  - Navigateur : `http://localhost:8000/docs` (ou `/api/documentation`)
  - Collection Postman synchronisée : `storage/app/private/scribe/collection.json`
- **Fichiers publics / Avatars** : `http://localhost:8000/storage/avatars/<nom_fichier>`

### Headers HTTP Recommandés
Pour toutes les requêtes :
```http
Accept: application/json
Content-Type: application/json
```
Pour les routes authentifiées :
```http
Authorization: Bearer <access_token>
```
Optionnel pour l'identification de l'appareil (sessions actives) :
```http
X-Device-Name: iPhone 15 Pro
```

---

## 🔑 Authentification & Rôles Système

L'API s'appuie sur **Laravel Passport** (OAuth2 / Personal Access Tokens).

### Distinction Rôles vs Badges
- **Rôle Système** : Gestion des autorisations techniques strictes (`user` ou `admin`).
  - Tout nouvel utilisateur inscrit via `/api/auth/signup` se voit attribuer automatiquement le rôle système `user`.
  - Seul un compte ayant le rôle `admin` peut accéder aux routes `/api/roles`, `/api/users` et au CRUD géo.
- **Badges Métier** : Attributs métier futurs (`Bailleur`, `Agent Certifié`, `Locataire Vérifié`) qui n'altèrent pas les droits système.

### Durée de Validité des Tokens (`persistent`)
Lors de la connexion (`/api/auth/signin`), vous pouvez spécifier le booléen `persistent` :
- `persistent: true` : Le jeton d'accès est valide pendant **1 an**.
- `persistent: false` (ou omis) : Le jeton d'accès expire après **7 jours**.

---

## 👤 Format Universel du Profil (`UserResource`)

Toutes les routes retournant le profil utilisateur (`GET /api/user`, `GET /api/profile`, etc.) utilisent la ressource unifiée [UserResource](file:///c:/Users/DEUGOUE%20Floriane/Documents/GitHub/bango_backend/app/Http/Resources/UserResource.php). 

Les données sensibles (`password`, `two_factor_secret`, `backup_codes`, `pending_email_code`, `pending_email_attempts`, `locked_until`) sont **strictement masquées**.

```json
{
  "id": 1,
  "email": "flora@example.com",
  "phone": "+237699001122",
  "handle": "flora.deugoue",
  "displayName": "Flora Deugoue",
  "avatarUrl": "http://localhost:8000/storage/avatars/avatar_670b1a2.jpg",
  "bio": "Passionnée d'immobilier et de tech.",
  "birthDate": "2000-05-15",
  "countryCode": "CM",
  "countryId": 1,
  "city": "Douala",
  "cityId": 1,
  "neighbourhood": "Akwa",
  "neighbourhoodId": 2,
  "locale": "fr",
  "isVerified": false,
  "accountType": "personal",
  "gender": "woman",
  "genderHidden": false,
  "onboardingProgress": {
    "completed": ["credentials", "identity", "phone", "interests"],
    "updatedAt": "2026-10-13T12:00:00Z"
  },
  "interests": ["realestate", "tech", "food"]
}
```

---

## 🔓 1. Endpoints PUBLICS (Aucun token requis)

---

### 🔐 Authentification, Inscription & 2FA (`/api/auth`)

#### `POST /api/auth/signup` — Inscription
Crée un nouveau compte utilisateur avec attribution automatique du rôle `user` et génération d'un `handle` unique.
- **Headers** : `Accept: application/json`, `Content-Type: application/json`
- **Body** :
  ```json
  {
    "email": "user@example.com",
    "password": "Password123"
  }
  ```
- **Règles de validation du mot de passe** :
  - Minimum 8 caractères (`min-length`).
  - Au moins un chiffre (`needs-digit`).
  - Aucune séquence évidente interdite type *1234*, *abcd*, *azerty*, *qwerty* (`no-sequence`).
- **Réponse Succès (201 Created)** :
  ```json
  {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOi...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "handle": "user",
      "displayName": "user"
    }
  }
  ```
- **Erreurs fréquentes** :
  - `409 Conflict` : Email déjà utilisé :
    ```json
    {
      "kind": "email-taken",
      "existingHandle": "user.d",
      "displayName": "Flora",
      "lastSeenAt": "2026-10-12T15:00:00Z"
    }
    ```
  - `422 Unprocessable Content` : Mot de passe non conforme :
    ```json
    {
      "kind": "weak-password",
      "failed": ["min-length", "needs-digit"]
    }
    ```

---

#### `POST /api/auth/signin` — Connexion
Authentifie un utilisateur via son email ou son numéro de téléphone.
- **Body** :
  ```json
  {
    "identifier": "user@example.com",  // ou "+237699001122"
    "password": "Password123",
    "persistent": true,               // optionnel : true (1 an), false (7 jours)
    "device_name": "iPhone 15 Pro"    // optionnel : nom de l'appareil
  }
  ```
- **Comportements clés** :
  - **Reprise après mise en pause** : Si le compte était en statut `paused`, la connexion réussie réactive automatiquement le compte (`status = active`).
  - **Double Authentification (2FA)** : Si l'utilisateur a activé la 2FA (SMS ou Email), le serveur envoie un code OTP et répond avec le challenge à valider :
    ```json
    // HTTP 200 OK
    {
      "kind": "second-factor",
      "destination": "u***r@example.com",
      "challenge": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0"
    }
    ```
- **Réponse Succès direct (200 OK — sans 2FA)** :
  ```json
  {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOi...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "handle": "user",
      "display_name": "User Name",
      "avatar_url": null,
      "is_verified": false
    }
  }
  ```
- **Erreurs & Statuts Spéciaux** :
  - `401 Unauthorized` : Identifiants incorrects (compteur restant avant verrouillage) :
    ```json
    {
      "kind": "invalid-credentials",
      "attemptsLeft": 4
    }
    ```
  - `429 Too Many Requests` : Verrouillage après 5 tentatives infructueuses :
    ```json
    {
      "kind": "locked",
      "minutes": 15,
      "until": "2026-10-13T13:45:00Z"
    }
    ```
  - `403 Forbidden` :
    - Compte suspendu : `{"kind": "suspended"}`
    - Compte bloqué : `{"kind": "blocked"}`
    - Compte supprimé : `{"kind": "deleted"}`
    - Compte programmé pour suppression (délai de 30 jours) :
      ```json
      {
        "kind": "scheduled_for_deletion",
        "scheduled_for_deletion_at": "2026-11-10T12:00:00Z"
      }
      ```

---

#### `POST /api/auth/signin/second-factor` — Validation 2FA
Valide le challenge de double authentification émis lors du signin.
- **Body** :
  ```json
  {
    "code": "123456",                   // code OTP à 6 chiffres OU code de secours (backup code)
    "challenge": "a1b2c3d4e5f6...",     // fourni par la réponse signin
    "identifier": "user@example.com"    // optionnel de secours si le challenge est omis
  }
  ```
- **Réponse Succès (200 OK)** :
  ```json
  {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOi...",
    "user": {
      "id": 1,
      "email": "user@example.com",
      "handle": "user",
      "display_name": "User Name",
      "avatar_url": null,
      "is_verified": false
    }
  }
  ```
- **Erreurs** :
  - `422 Unprocessable Content` : Challenge expiré ou code invalide :
    `{"kind": "challenge-expired"}` ou `{"kind": "invalid", "attemptsLeft": 3}`.

---

### 🛡️ État et Restauration de Compte (`/api/auth`)

#### `POST /api/auth/account-state` — Inspection Compte Suspendu / Supprimé (AU-26 / AU-27)
Permet à l'application mobile d'afficher les écrans informatifs expliquant pourquoi un utilisateur ne peut pas se connecter (motifs de suspension et date de fin, ou date effective de suppression).
- **Body** :
  ```json
  {
    "identifier": "user@example.com",
    "password": "Password123"
  }
  ```
- **Réponse Succès (200 OK)** :
  ```json
  {
    "displayName": "Flora Deugoue",
    "handle": "flora.d",
    "suspension": {
      "reason": "Non respect des conditions d'utilisation.",
      "from": "2026-10-10T10:00:00Z",
      "until": "2026-10-20T10:00:00Z"
    },
    "deletion": {
      "requestedAt": "2026-10-01T12:00:00Z",
      "effectiveAt": "2026-10-31T12:00:00Z"
    }
  }
  ```
  *(Si le compte n'est pas suspendu ou pas en cours de suppression, la clé respective vaut `null`).*
- **Erreur** : `401 Unauthorized` `{"kind": "invalid-credentials"}` si le mot de passe est faux.

---

#### `POST /api/auth/restore-account` — Annuler la suppression du compte
Restaure un compte supprimé tant que la date de grâce de 30 jours n'est pas échue.
- **Body** :
  ```json
  {
    "identifier": "user@example.com",
    "password": "Password123"
  }
  ```
- **Réponse Succès (200 OK)** : `{"success": true}`
- **Erreurs** :
  - `401 Unauthorized` : `{"kind": "invalid-credentials"}`
  - `400 Bad Request` : `{"kind": "not-deleted"}` (le compte n'était pas supprimé)
  - `403 Forbidden` : `{"kind": "permanently-deleted"}` (le délai de grâce de 30 jours est dépassé)

---

### 🔄 Réinitialisation de Mot de Passe

#### Parcours 1 : Récupération par OTP (SMS ou Email)
1. **Demande de code** : `POST /api/auth/forgot-password`
   - Body : `{"channel": "sms", "identifier": "+237699001122"}` (ou `channel: "email"`).
   - Réponse : `{"success": true, "message": "If your account exists, an OTP has been sent."}` (anti-énumération).
2. **Vérification du code** : `POST /api/auth/forgot-password/verify`
   - Body : `{"channel": "sms", "identifier": "+237699001122", "code": "123456"}`
   - Réponse : `{"success": true, "reset_token": "64_chars_token..."}`
3. **Définition du nouveau mot de passe** : `POST /api/auth/forgot-password/reset`
   - Body :
     ```json
     {
       "channel": "sms",
       "identifier": "+237699001122",
       "token": "64_chars_token...",
       "password": "NewPassword123",
       "password_confirmation": "NewPassword123"
     }
     ```
   - Réponse : `{"success": true, "message": "Password reset successfully."}`

#### Parcours 2 : Récupération par Lien Magique (Email uniquement)
1. **Demande de lien** : `POST /api/auth/reset-password/request`
   - Body : `{"email": "user@example.com"}`
   - Réponse : `{"success": true, "message": "If your account exists, a reset link has been sent to your email."}`
2. **Définition du nouveau mot de passe** : `POST /api/auth/reset-password`
   - Body :
     ```json
     {
       "token": "token_extrait_de_l_url",
       "password": "NewPassword123",
       "password_confirmation": "NewPassword123"
     }
     ```
   - Réponse : `{"success": true, "message": "Password reset successfully."}`

---

### 🌟 Centres d'intérêt (`/api/interests`)

#### `GET /api/interests` — Liste publique
Renvoie la liste des 10 centres d'intérêt prédéfinis du système :
- **Identifiants officiels** : `realestate`, `business`, `sport`, `music`, `fashion`, `food`, `tech`, `travel`, `education`, `entertainment`.
- **Réponse (200 OK)** :
  ```json
  [
    {
      "id": "realestate",
      "name_fr": "Immobilier",
      "name_en": "Real Estate",
      "icon": "home",
      "category": "lifestyle"
    },
    {
      "id": "tech",
      "name_fr": "Tech & Innovation",
      "name_en": "Technology",
      "icon": "cpu",
      "category": "business"
    }
  ]
  ```

---

### 🌍 Référentiel Géographique (Lecture Publique) (`/api/geo`)

Permet de charger les données géographiques en cascade (Pays -> Villes -> Quartiers) avec support des filtres :

| Endpoint | Filtres Query Params | Description |
|---|---|---|
| `GET /api/geo/countries` | `?is_active=1` | Liste des pays supportés (CEMAC, UEMOA, etc.). Contient `id`, `name`, `name_en`, `iso2`, `iso3`, `phone_code`, `currency`. |
| `GET /api/geo/countries/{id}` | - | Détails d'un pays avec `currency`, `cities`, `operators`. |
| `GET /api/geo/cities` | `?country_id=1&is_active=1` | Villes d'un pays. |
| `GET /api/geo/cities/{id}` | - | Ville avec ses quartiers (`neighbourhoods`). |
| `GET /api/geo/neighbourhoods` | `?city_id=1&is_active=1` | Quartiers d'une ville. |
| `GET /api/geo/neighbourhoods/{id}` | - | Détails d'un quartier. |
| `GET /api/geo/currencies` | `?is_active=1` | Devises disponibles (`XAF`, `XOF`, `EUR`, `USD`). |
| `GET /api/geo/operators` | `?country_id=1&is_active=1` | Opérateurs télécoms (MTN, Orange, Moov, Camtel, etc.). |

---

## 🔒 2. Endpoints UTILISATEUR CONNECTÉ (Token Requis)

Nécessitent le Header `Authorization: Bearer <access_token>`.

---

### 👤 Session Utilisateur

- `GET /api/user` : Renvoie le profil complet au format `UserResource` (avec les centres d'intérêt chargés).
- `POST /api/auth/logout` : Déconnexion (révoque le jeton d'accès courant).

---

### 📱 Profil Utilisateur (`/api/profile`)

#### `GET /api/profile`
Renvoie le profil de l'utilisateur connecté encapsulé dans `UserResource`.

#### `POST /api/profile/handle-check` — Tester la disponibilité d'un pseudo
- **Body** : `{"handle": "monpseudo"}`
- **Réponses possibles (200 OK)** :
  - Disponible : `{"state": "free"}`
  - Pris : `{"state": "taken", "since": "2024", "suggestions": ["monpseudo12", "monpseudo_99"]}`
  - Erreurs de forme : `{"state": "too-short"}`, `{"state": "too-long"}`, `{"state": "bad-shape"}`, `{"state": "reserved"}`.

#### `PUT /api/profile/identity` — Mettre à jour Nom & Handle
- **Body** :
  ```json
  {
    "displayName": "Flora Deugoue",
    "handle": "flora.d"
  }
  ```
- **Règles** : Handle de 3 à 20 caractères, uniquement lettres minuscules, chiffres et points (`^[a-z0-9.]+$`).

#### `PUT /api/profile/bio` — Mettre à jour la Biographie
- **Body** :
  ```json
  {
    "bio": "Investisseur et passionné d'architecture urbaine."
  }
  ```
- **Validation** : Chaîne optionnelle de maximum 500 caractères.

#### `PUT /api/profile/birthdate` — Date de Naissance
- **Body** : `{"birthDate": "2000-05-15"}`
- **Règle** : L'utilisateur doit avoir au moins 18 ans révolus (`before_or_equal: 18 ans`).

#### `PUT /api/profile/gender` — Genre & Visibilité
- **Body** :
  ```json
  {
    "gender": "woman",   // "woman", "man", "other", "undisclosed"
    "hidden": false       // masquer ou afficher publiquement
  }
  ```

#### `PUT /api/profile/location` — Localisation
Met à jour le pays, la ville et le quartier de l'utilisateur. Vous pouvez envoyer soit les identifiants techniques, soit les codes ISO et noms directs :
- **Body** :
  ```json
  {
    "countryCode": "CM",     // ou "countryId": 1
    "cityId": 1,             // ou "city": "Douala"
    "neighbourhoodId": 2     // ou "neighbourhood": "Akwa"
  }
  ```

#### `PUT /api/profile/interests` — Centres d'intérêt
- **Body** :
  ```json
  {
    "interests": ["realestate", "tech", "food"]
  }
  ```
- **Règle** : Minimum 3 identifiants sélectionnés parmi la liste officielle (`/api/interests`).

#### `PUT /api/profile/avatar` — Téléversement de Photo de Profil
- **Format** : `multipart/form-data`
- **Champ** : `avatar` (Fichier image jpeg, png, webp, gif — max 5 Mo).
- **Réponse (200 OK)** :
  ```json
  {
    "avatarUrl": "http://localhost:8000/storage/avatars/abc123xyz.jpg"
  }
  ```

---

### 📲 Codes OTP (`/api/otp`)

Géré selon la norme **AU-12** (5 tentatives autorisées, puis verrouillage 15 minutes).

#### `POST /api/otp/request` — Demander un code
- **Body** : `{"channel": "sms", "identifier": "+237699001122"}` (ou `channel: "email"`).
- **Routage International SMS (Zomloa)** : Le système détecte automatiquement l'indicatif téléphonique (+237 Cameroun, +225 Côte d'Ivoire, +221 Sénégal, +241 Gabon, +33 France, etc.).
- **Réponse (200 OK)** : `{"success": true}`
- **Erreurs** :
  - `409 Conflict` : Numéro déjà associé à un autre compte :
    ```json
    {
      "kind": "number-taken",
      "handle": "autre.pseudo",
      "createdAt": "2026-01-10T12:00:00Z"
    }
    ```
  - `429 Too Many Requests` : Verrouillage actif :
    ```json
    {
      "kind": "locked",
      "minutes": 15,
      "until": "2026-10-13T13:45:00Z"
    }
    ```

#### `POST /api/otp/verify` — Valider le code saisi
- **Body** :
  ```json
  {
    "channel": "sms",
    "identifier": "+237699001122",
    "code": "123456"
  }
  ```
- **Réponse (200 OK)** : `{"success": true}` (marque automatiquement l'email ou le téléphone comme vérifié).
- **Erreurs** :
  - `422 Unprocessable Content` : Code erroné :
    ```json
    {
      "kind": "invalid",
      "attemptsLeft": 4
    }
    ```
  - `429 Too Many Requests` : Déclenché au 5ᵉ échec consécutif :
    ```json
    {
      "kind": "locked",
      "minutes": 15,
      "until": "2026-10-13T13:45:00Z"
    }
    ```

#### `POST /api/otp/resend` — Renvoyer le code
- **Body** : `{"channel": "sms", "identifier": "+237699001122"}`
- **Réponse (200 OK)** : `{"success": true, "resendAfterSeconds": 60}`

---

### 🚀 Onboarding (`/api/onboarding`)

#### `POST /api/onboarding/complete` — Enregistrer une étape
- **Body** :
  ```json
  {
    "step": "interests"
  }
  ```
  *Étapes valides : `credentials`, `identity`, `phone`, `profile`, `interests`, `housing`.*
- **Réponse (200 OK)** :
  ```json
  {
    "completed": ["credentials", "identity", "phone", "interests"],
    "updatedAt": "2026-10-13T12:30:00Z"
  }
  ```

---

### 🏠 Recherche de Logement (`/api/housing/search`)

- `GET /api/housing/search` : Renvoie les critères sauvegardés de l'utilisateur (ou `null`).
- `PUT /api/housing/search` : Sauvegarde les critères de recherche :
  ```json
  {
    "intent": "active",             // "active", "browsing", "no"
    "types": ["apartment", "villa"],
    "budget": [150000, 450000],
    "currency": "XAF",
    "city": "Douala",
    "neighbourhoods": ["Akwa", "Bonapriso"],
    "place": "Cameroun"
  }
  ```

---

### ⚙️ Paramètres & Sécurité (`/api/settings`)

#### Changement d'Adresse Email (avec envoi de code par Resend)
1. **Initier la demande** : `POST /api/settings/email/request`
   - Body : `{"address": "nouvelle@example.com", "password": "MotDePasseActuel"}`
   - Comportement : Vérifie le mot de passe, l'unicité du nouvel email, génère un OTP à 6 chiffres et l'expédie immédiatement par email via **Resend**.
   - Réponse : `{"address": "nouvelle@example.com", "expiresAt": "2026-10-13T13:15:00Z", "attemptsLeft": 4}`
   - Erreur : `403` `{"kind": "bad-password"}` si le mot de passe actuel est faux ; `409` `{"kind": "taken"}` si l'email est déjà pris.
2. **Valider le code** : `POST /api/settings/email/verify`
   - Body : `{"code": "123456"}`
   - Réponse : `{"previous": "ancienne@example.com"}` (L'adresse email du compte est mise à jour).
   - Erreurs : `422` `{"reason": "wrong", "attemptsLeft": 3}` ; `410` `{"reason": "expired", "attemptsLeft": 0}`.
3. **Renvoyer le code** : `POST /api/settings/email/resend`
4. **Annuler la demande** : `POST /api/settings/email/cancel`

---

#### Changement de Mot de Passe
- `PUT /api/settings/password`
- **Body** :
  ```json
  {
    "current_password": "AncienPassword123",
    "password": "NouveauPassword123",
    "password_confirmation": "NouveauPassword123"
  }
  ```
- **Réponse (200 OK)** : `{"success": true}`
- **Erreur** : `403 Forbidden` `{"kind": "bad-password"}` si `current_password` est incorrect.

---

#### Gestion des Sessions & Appareils Connectés
- `GET /api/settings/sessions` : Liste les appareils connectés avec détection d'appareils inhabituels :
  ```json
  {
    "sessions": [
      {
        "id": "9b12d5e4...",
        "device": "iPhone 15 Pro",
        "current": true,
        "unusual": false,
        "acknowledged": true,
        "place": "Douala, Cameroun",
        "last_used_at": "2026-10-13T12:00:00Z"
      },
      {
        "id": "9b13fa21...",
        "device": "MacBook Air",
        "current": false,
        "unusual": true,
        "acknowledged": false,
        "place": "Yaoundé, Cameroun",
        "last_used_at": "2026-10-11T18:30:00Z"
      }
    ],
    "twoFactor": "email",
    "backupCodesCount": 8
  }
  ```
- `DELETE /api/settings/sessions/{id}` : **Déconnecter un appareil précis** (révoque son token Passport).
- `POST /api/settings/sessions/{id}/acknowledge` : Marquer un appareil inhabituel comme reconnu/de confiance.
- `POST /api/settings/secure` : **Bouton d'urgence** : Révoque instantanément toutes les sessions sauf la session actuelle.
  - Réponse : `{"signedOut": 2, "passwordPending": true}`

---

#### Mise en Pause & Suppression de Compte
- `POST /api/settings/account/pause` — Mettre en pause le compte
  - Body : `{"password": "MonPassword123"}`
  - Comportement : Passe le statut à `paused` et déconnecte immédiatement l'utilisateur.
  - *Rappel : La pause est automatiquement levée lors de la prochaine connexion sur `/api/auth/signin`.*
- `DELETE /api/settings/account` — Demander la suppression du compte
  - Body : `{"password": "MonPassword123", "confirmation": "SUPPRIMER"}`
  - Comportement : Enclenche le délai de grâce de 30 jours, applique un soft-delete sur le modèle User et révoque tous les accès.

---

## 🛡️ 3. Endpoints ADMINISTRATEUR (Token + Rôle 'admin')

Ces routes sont strictement réservées au back-office d'administration. Un utilisateur standard recevra une réponse **HTTP 403 Forbidden**.

### 👥 Rôles Système (`/api/roles`)
- `GET /api/roles` : Liste des rôles système.
- `POST /api/roles` : Créer un rôle (`name`, `slug`, `description`, `is_active`).
- `GET /api/roles/{id}` : Détails d'un rôle.
- `PUT /api/roles/{id}` : Modifier un rôle.
- `DELETE /api/roles/{id}` : Supprimer un rôle (impossible pour `admin` et `user`).
- `GET /api/roles/{id}/users` : Liste des utilisateurs rattachés à ce rôle.

### 👮 Modération Utilisateurs & Sessions (`/api/users`)
- `GET /api/users` : Liste paginée des utilisateurs.
  - *Filtres disponibles* : `display_name`, `email`, `phone`, `handle`, `status`, `is_verified`.
  - *Tris* : `sort_by` (`created_at`, `display_name`, `email`, `status`), `sort_order` (`asc`, `desc`), `per_page` (défaut: 15).
- `GET /api/users/{id}` : Fiche détaillée complète avec relations (`role`, `country`, `city`, `neighbourhood`, `interests`, `housingSearch`).
- `PATCH /api/users/{id}` : Modifier les informations de base.
- `POST /api/users/{id}/suspend` : Suspendre le compte (expulsion immédiate de toutes les sessions).
- `POST /api/users/{id}/unsuspend` : Réactiver le compte (`status = active`).
- `POST /api/users/{id}/block` : Bloquer définitivement l'accès.
- `POST /api/users/{id}/unblock` : Débloquer l'accès.
- `DELETE /api/users/{id}` : Soft delete de l'utilisateur.
- `GET /api/users/{id}/sessions` : Voir les sessions actives d'un utilisateur.
- `DELETE /api/users/{id}/sessions/{session_id}` : Révoquer une session précise.
- `DELETE /api/users/{id}/sessions` : Révoquer toutes les sessions de l'utilisateur.
- `GET /api/users/{id}/role` : Consulter le rôle actuel d'un utilisateur.
- `PATCH /api/users/{id}/role` : Modifier le rôle assigné (`role_id` ou `role_slug`).

### 🗺️ Gestion Géographique Admin (`/api/geo`)
Permet la création, modification et suppression des données géographiques :
- `POST, PUT, DELETE /api/geo/countries`
- `POST, PUT, DELETE /api/geo/cities`
- `POST, PUT, DELETE /api/geo/neighbourhoods`
- `POST, PUT, DELETE /api/geo/currencies`
- `POST, PUT, DELETE /api/geo/operators`

---

## ⚠️ Tableau Exhaustif des Erreurs BANGO

| Code HTTP | Payload JSON retourné | Contexte / Cause |
|---|---|---|
| **400** | `{"kind": "bad-confirmation"}` | Suppression de compte : le texte n'est pas exactement "SUPPRIMER" |
| **400** | `{"kind": "not-deleted"}` | Tentative de restauration sur un compte non supprimé |
| **401** | `{"kind": "invalid-credentials", "attemptsLeft": 4}` | Identifiant ou mot de passe incorrect lors du login |
| **403** | `{"kind": "bad-password"}` | Mot de passe incorrect lors d'une action sensible (pause, suppression, changement email) |
| **403** | `{"kind": "suspended"}` | Connexion refusée car le compte est temporairement suspendu |
| **403** | `{"kind": "blocked"}` | Connexion refusée car le compte est bloqué définitivement |
| **403** | `{"kind": "deleted"}` | Connexion refusée car le compte est supprimé |
| **403** | `{"kind": "scheduled_for_deletion", "scheduled_for_deletion_at": "..."}` | Compte en sursis de suppression (30 jours) |
| **403** | `{"kind": "permanently-deleted"}` | Restauration impossible : le délai de 30 jours est dépassé |
| **403** | `{"message": "Accès interdit. Droits administrateur requis."}` | Accès à une route back-office sans le rôle `admin` |
| **404** | `{"message": "Session not found"}` | Session inexistante lors d'une tentative de révocation |
| **409** | `{"kind": "email-taken", "existingHandle": "...", "displayName": "..."}` | Inscription avec une adresse email déjà enregistrée |
| **409** | `{"kind": "number-taken", "handle": "...", "createdAt": "..."}` | Demande d'OTP pour un téléphone déjà lié à un autre compte |
| **409** | `{"kind": "taken"}` | Le nouvel email demandé est déjà pris par un autre utilisateur |
| **410** | `{"reason": "expired", "attemptsLeft": 0}` | Code de validation du changement d'email expiré ou épuisé |
| **422** | `{"kind": "weak-password", "failed": ["min-length", "needs-digit"]}` | Inscription : mot de passe ne respectant pas les règles de sécurité |
| **422** | `{"kind": "invalid", "attemptsLeft": 3}` | Code OTP incorrect (renvoie le nombre d'essais restants) |
| **422** | `{"kind": "expired"}` | Code OTP périmé |
| **422** | `{"kind": "challenge-expired"}` | Le challenge 2FA de connexion a expiré (10 minutes) |
| **422** | `{"kind": "same"}` | Le nouvel email renseigné est identique à l'actuel |
| **422** | `{"message": "The given data was invalid.", "errors": {...}}` | Échec standard de validation de formulaire Laravel |
| **429** | `{"kind": "locked", "minutes": 15, "until": "2026-10-13T14:00:00Z"}` | Verrouillage temporaire après 5 échecs de connexion ou d'OTP |

---

*Ce document est continuellement synchronisé avec le code source de l'application. Bon développement !* 🚀
