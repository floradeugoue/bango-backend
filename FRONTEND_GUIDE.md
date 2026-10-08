# BANGO - Guide d'Intégration Frontend

Bienvenue sur le projet backend de BANGO ! Ce document a été conçu pour faciliter au maximum la vie de l'équipe Frontend.
Il reflète l'organisation fonctionnelle de l'API et les règles d'autorisation strictes mises en place.

## 🔗 Documentation Interactive de l'API (Swagger / OpenAPI)

Toute la documentation de l'API est générée automatiquement avec Scribe. Elle est triée par groupes cohérents (Auth, Profile, Geo, etc.).
Elle contient :
- Tous les Endpoints disponibles
- Le format exact des JSON attendus (Body Parameters)
- Les exemples de réponses (Succès et Erreurs)

Pour y accéder :
1. Démarrez le backend : `php artisan serve`
2. Ouvrez votre navigateur sur : **[http://localhost:8000/docs](http://localhost:8000/docs)**

## 🛡️ Postman Collection

Une collection Postman est également générée automatiquement. Vous pouvez l'importer dans votre client HTTP préféré (Postman, Insomnia) depuis ce fichier :
`storage/app/private/scribe/collection.json`

---

## 🔑 Authentification (Passport) & Rôles Système

Le système utilise **Laravel Passport** pour l'authentification par jeton Bearer (`auth:api`).

**Règle d'or sur l'architecture BANGO :**
- **Passport** = Validation du Token d'authentification
- **Role Système** = Droits d'accès globaux au système (Un utilisateur possède **exactement un seul rôle système** : `user` ou `admin`)
- **Badge** = Rôle métier (ex: `Bailleur`, `Agent`). Les badges n'interfèrent pas avec le rôle système. (ex: Un compte de rôle `user` peut avoir le badge `Bailleur`).

Lors de l'inscription via `/api/auth/signup`, le rôle système `user` est assigné **automatiquement**.

**Exemple de retour lors du Signup / Signin :**
```json
{
  "token": "eyJ0eXAiOiJKV1QiLCJhbG...",
  "user": {
    "id": 1,
    "email": "test@example.com",
    "handle": "pseudo",
    "role": {
      "id": 2,
      "name": "Utilisateur",
      "slug": "user"
    }
  }
}
```

Pour toutes les routes protégées (USER et ADMIN), ajoutez ce Header HTTP à vos requêtes :
`Authorization: Bearer VOTRE_TOKEN`

---

## 🏗️ Architecture des Endpoints API

Les endpoints sont désormais classés en **3 niveaux de privilèges**.

### 🔓 1. Endpoints PUBLIC (Aucun token requis)

Ces API sont librement accessibles depuis le Frontend sans authentification.

**Authentification (AUTH)**
- `POST /api/auth/signup` : Inscription
- `POST /api/auth/signin` : Connexion (inclut Rate Limiting)

**Référentiel Géographique (GEO) - Lecture seule**
- `GET /api/geo/countries` (et `/api/geo/countries/{id}`)
- `GET /api/geo/currencies` (et `/api/geo/currencies/{id}`)
- `GET /api/geo/cities` (et `/api/geo/cities/{id}`)
- `GET /api/geo/neighbourhoods` (et `/api/geo/neighbourhoods/{id}`)
- `GET /api/geo/operators` (et `/api/geo/operators/{id}`)

---

### 🔒 2. Endpoints USER (Token Passport Requis)

Nécessitent simplement un utilisateur authentifié et connecté avec l'application (le rôle système standard `user` suffit).

**Profil Utilisateur (PROFILE)**
- `GET /api/profile` : Voir le profil
- `POST /api/profile/handle-check` : Vérifier un pseudo
- `PUT /api/profile/identity` : Maj Nom + Pseudo
- `PUT /api/profile/birthdate` : Maj Date (Refus si < 18 ans)
- `PUT /api/profile/gender` : Maj Genre
- `PUT /api/profile/location` : Maj Pays/Ville
- `PUT /api/profile/interests` : Maj Intérêts (Tableau de chaînes)
- `PUT /api/profile/avatar` : Uploader l'image

**Validation (OTP)**
- `POST /api/otp/request` : Demander un code
- `POST /api/otp/verify` : Valider un code
- `POST /api/otp/resend` : Renvoyer un code

**Progression Onboarding**
- `POST /api/onboarding/complete` : Sauvegarder la progression `{"completed": ["identity", "gender"]}`

**Recherche de logement (HOUSING)**
- `GET /api/housing/search` : Voir les critères de recherche
- `PUT /api/housing/search` : Enregistrer les critères

**Sécurité & Paramètres (SETTINGS)**
- **Email** : `/api/settings/email/request`, `verify`, `resend`, `cancel`
- **Mot de passe** : `PUT /api/settings/password`
- **Sessions & Sécurité** : `POST /api/settings/secure`, `GET /api/settings/sessions`, `POST /api/settings/sessions/{id}/acknowledge`
- **Gestion du compte** : `POST /api/settings/account/pause`, `DELETE /api/settings/account`

---

### 🛡️ 3. Endpoints ADMIN (Token Passport + Rôle 'admin' Requis)

Ces endpoints sont réservés aux administrateurs de la plateforme et renverront une erreur `403 Forbidden` si appelés par un rôle `user`.

**Gestion des Rôles Système (ROLES)**
- `GET, POST, PUT, DELETE /api/roles` : CRUD sur les rôles systèmes
- `GET /api/roles/{id}/users` : Liste des utilisateurs ayant ce rôle
- `GET /api/users/{user}/role` : Voir le rôle d'un utilisateur spécifique
- `PATCH /api/users/{user}/role` : Assigner un nouveau rôle à un utilisateur

**Référentiel Géographique (GEO) - Modification**
- `POST, PUT, DELETE /api/geo/countries`
- `POST, PUT, DELETE /api/geo/currencies`
- `POST, PUT, DELETE /api/geo/cities`
- `POST, PUT, DELETE /api/geo/neighbourhoods`
- `POST, PUT, DELETE /api/geo/operators`

---

## ⚠️ Gestion des Erreurs (Format Custom BANGO)

Le backend respecte strictement les contrats d'erreurs définis pour l'expérience Frontend.
Certains endpoints renvoient un format custom JSON plutôt que l'erreur classique de Laravel (codes 422, 409, 429).

**Exemple de Rate Limiting (Route signin bloquée) :**
```json
{
  "kind": "locked",
  "minutes": 15,
  "until": "2026-10-07T20:45:00Z"
}
```

**Exemple pour un Mot de passe faible (Signup) :**
```json
{
  "kind": "weak-password",
  "failed": ["min-length", "needs-digit"]
}
```

**Exemple pour un Email déjà pris (Signup) :**
```json
{
  "kind": "email-taken",
  "existingHandle": "taken_user",
  "displayName": "Taken User",
  "avatarUrl": "https://..."
}
```

---
*Happy Coding !* 🚀
