# BANGO — Documentation Technique : Module LG (Légal/RGPD) & EDGE (Cas Limites)
## CMS Légal Versionné, Réacceptation des CGU, Résolution Universelle & Codes Sémantiques

**Projet :** BANGO Backend (Laravel 13 / PHP 8.4 / PostgreSQL 18.6)  
**Date :** 16 Octobre 2026  
**Statut :** Validé — 100 % opérationnel, testé (28 tests passés, 156 assertions)

---

## 1. Vue d'Ensemble des Fonctionnalités

Cette étape finalise quatre sous-systèmes transverses fondamentaux pour la conformité et l'expérience utilisateur de BANGO :
1. **LG — CMS Légal Versionné** : Stockage et distribution dynamique des textes juridiques (`terms-of-service`, `privacy-policy`, `community-charter`) avec gestion de versions immuables, horodatage d'entrée en vigueur et fallback linguistique automatique.
2. **LG — Acceptation & Réacceptation Obligatoire des CGU (`LG-07`)** : Traçabilité serveur de chaque acceptation dans une table d'audit `terms_acceptances`, synchronisation sur le compte utilisateur et middleware bloquant `EnsureTermsAccepted` lors de la publication d'une version obligatoire.
3. **EDGE — Résolution des Liens Profonds Universels** : Endpoint `GET /api/content/resolve-link?path=...` capable d'analyser les chemins canoniques (`/posts/{id}`, `/reels/{id}`, `/listings/{id}`, `/users/{id}`, `/@handle`, `/pages/{handle}`) et les liens courts (`/r/{code}`), en vérifiant l'état réel de disponibilité sans fuite de données privées.
4. **EDGE — Codes d'Erreur HTTP Sémantiques** : Normalisation des réponses API :
   - `404 Not Found` : Ressource inexistante ou non accessible (confidentialité).
   - `410 Gone` : Ressource identifiée mais définitivement supprimée par son auteur.
   - `423 Locked` : Ressource existante mais temporairement gelée ou verrouillée par une décision de modération ou une suspension.

---

## 2. Inventaire des Endpoints

| Méthode | URI | Contrôleur & Action | Authentification | Scope / Rôle | Statuts HTTP Possibles |
| :--- | :--- | :--- | :---: | :--- | :---: |
| `GET` | `/api/legal/documents/{slug}` | `LegalDocumentController@show` | Public | Lecture des documents publiés | `200`, `404` |
| `POST` | `/api/legal/accept-terms` | `TermsAcceptanceController@store` | Requise (Bearer) | Utilisateur connecté (`auth:api`) | `200`, `401`, `422` |
| `GET` | `/api/content/resolve-link` | `LinkResolverController@resolve` | Optionnelle (Bearer) | Public / Dépend de la cible | `200`, `404`, `410`, `423` |

---

## 3. Détails Techniques & Contrats API

### 3.1. Consultation des Documents Légaux (`GET /api/legal/documents/{slug}`)

- **URI :** `GET /api/legal/documents/{slug}?locale=fr&version=1.0.0`
- **Paramètres de requête :**
  - `slug` *(segment d'URL, obligatoire)* : `terms-of-service`, `privacy-policy`, `community-charter`, etc.
  - `locale` *(query, optionnel)* : Code langue (`fr`, `en`, etc. Défaut : `fr`).
  - `version` *(query, optionnel)* : Numéro explicite de version. En son absence, l'API sélectionne automatiquement la version publiée la plus récente (`effective_date DESC, id DESC`).
- **Comportement Linguistique :**
  - Recherche prioritaire dans la langue demandée (`locale`).
  - Si la traduction n'existe pas, application d'un fallback transparent vers la langue par défaut (`fr`).
  - Si aucune version publiée n'existe pour ce slug, l'API retourne une erreur `404 DOCUMENT_NOT_FOUND`.
  - Les brouillons (`is_published = false`) et les versions futures (`effective_date > now()`) ne sont jamais exposés.

#### Exemple de Réponse (`200 OK`) :
```json
{
  "data": {
    "id": 1,
    "slug": "terms-of-service",
    "version": "2.0.0",
    "locale": "fr",
    "title": "Conditions Générales d'Utilisation BANGO",
    "content_markdown": "# CGU BANGO\n\nBienvenue sur la plateforme...",
    "effective_date": "2026-10-15T00:00:00.000000Z",
    "is_mandatory": true,
    "updated_at": "2026-10-15T00:00:00.000000Z"
  }
}
```

---

### 3.2. Acceptation & Réacceptation des CGU (`POST /api/legal/accept-terms`)

- **URI :** `POST /api/legal/accept-terms`
- **Headers :** `Authorization: Bearer <token>`, `Accept: application/json`
- **Corps de la requête (JSON) :**
  ```json
  {
    "version": "2.0.0",
    "locale": "fr",
    "document_slug": "terms-of-service"
  }
  ```
- **Règles Fonctionnelles :**
  1. La version obligatoire est déterminée côté serveur via `LegalDocumentService::getMandatoryTermsVersion()`.
  2. Si le client envoie une version différente/obsolète par rapport à la version obligatoire, le serveur rejette l'appel avec une erreur `422 OUTDATED_TERMS_VERSION`.
  3. Si la version est omise ou correspond à la version obligatoire, l'acceptation est enregistrée de manière atomique et idempotente dans `terms_acceptances` (avec adresse IP et User-Agent).
  4. Les colonnes `terms_accepted_at` et `accepted_terms_version` de l'utilisateur sont immédiatement synchronisées.
  5. Une seconde soumission identique est idempotente et renvoie `200 OK` sans doublon en base.

#### Exemple de Réponse Succès (`200 OK`) :
```json
{
  "success": true,
  "message": "Conditions générales d'utilisation acceptées avec succès.",
  "version": "2.0.0",
  "document_slug": "terms-of-service",
  "accepted_at": "2026-10-16T19:30:00.000000Z",
  "is_compliant": true
}
```

#### Exemple de Réponse Version Obsolète (`422 Unprocessable Content`) :
```json
{
  "message": "Une nouvelle version des conditions générales d'utilisation est requise.",
  "error": "OUTDATED_TERMS_VERSION",
  "required_version": "2.0.0",
  "provided_version": "1.0.0",
  "document_slug": "terms-of-service"
}
```

---

### 3.3. Middleware de Blocage (`EnsureTermsAccepted`)

- **Alias :** `terms.accepted`
- **Rôle :** Vérifie que l'utilisateur connecté a accepté la version obligatoire courante des CGU avant d'accéder aux fonctionnalités protégées.
- **Comportement en cas de non-conformité :**
  - Retourne un code HTTP `403 Forbidden` standardisé.
  - Format d'erreur :
    ```json
    {
      "message": "Vous devez accepter la version obligatoire des conditions générales d'utilisation pour continuer.",
      "error": "TERMS_ACCEPTANCE_REQUIRED",
      "required_version": "2.0.0",
      "accepted_version": "1.0.0",
      "document_slug": "terms-of-service"
    }
    ```
- **Exemptions de Filtrage :**
  Les routes indispensables ne sont jamais bloquées :
  - `api/auth/*` (authentification, renouvellement de session, déconnexion)
  - `api/legal/*` (lecture des CGU, endpoint d'acceptation)
  - `api/otp/*` (vérification de numéro)
  - `api/geo/*` (données géographiques publiques)
  - `/up` (health check)

---

### 3.4. Résolution des Liens Profonds Universels (`GET /api/content/resolve-link`)

- **URI :** `GET /api/content/resolve-link?path=...`
- **Paramètre :** `path` *(string, obligatoire)*. Peut être :
  - Un lien court : `/r/abc123`
  - Une publication Square : `/posts/12`
  - Un Reel : `/reels/9d4e5f6a-...`
  - Une annonce immobilière : `/listings/8f1c2d3e-...`
  - Un profil utilisateur : `/users/42`, `/@jean_dupont`, `/p/jean_dupont`
  - Une Page Professionnelle : `/pages/agence_ocean`
  - Une URL complète : `https://bango.africa/posts/12` (assainissement automatique).
- **Règles de Confidentialité et Protection des Données :**
  - Pour les annonces immobilières, l'adresse exacte et confidentielle (`address`) n'est **jamais** exposée. Seul le repère public indicatif (`indicative_location`, `neighbourhood`, `city`) est renvoyé.
  - Pour les contenus privés (`audience = followers` ou compte privé), `requires_auth: true` et `is_accessible: false` sont retournés si l'utilisateur n'est pas authentifié ou n'est pas abonné.
  - Si un blocage relationnel existe entre les utilisateurs (bloqué/bloqueur), l'API retourne `404 Not Found`.

#### Exemple de Réponse (`200 OK`) :
```json
{
  "data": {
    "is_valid": true,
    "target_type": "post",
    "target_id": "12",
    "status": "available",
    "requires_auth": false,
    "is_accessible": true,
    "metadata": {
      "caption_preview": "Superbe après-midi au quartier Akwa !...",
      "author_handle": "jeandupont",
      "author_name": "Jean Dupont",
      "is_archived": false,
      "created_at": "2026-10-16T18:00:00.000000Z"
    },
    "short_link": {
      "code": "r8b2k9a1",
      "clicks_count": 3
    }
  }
}
```

---

### 3.5. Codes d'Erreur HTTP Sémantiques Normalisés

| Statut HTTP | Code Métier (`error`) | Signification Fonctionnelle | Déclenchement |
| :---: | :--- | :--- | :--- |
| **`404 Not Found`** | `RESOURCE_NOT_FOUND` / `SHORT_LINK_NOT_FOUND` | Ressource introuvable, lien court expiré ou compte bloqué. | Identifiant inconnu, code court inexistant ou filtrage de confidentialité. |
| **`410 Gone`** | `POST_GONE`, `REEL_GONE`, `LISTING_GONE`, `USER_GONE`, `PAGE_GONE` | Ressource connue mais supprimée définitivement par son auteur. | Modèle soft-deleted (`deleted_at IS NOT NULL`). Permet au frontend d'afficher un écran explicite « Contenu supprimé ». |
| **`423 Locked`** | `POST_LOCKED`, `REEL_LOCKED`, `LISTING_LOCKED`, `USER_LOCKED`, `PAGE_LOCKED` | Ressource existante mais temporairement verrouillée ou gelée par la modération. | Compte suspendu (`status = 'suspended'`), annonce rejetée, ou signalement résolu avec sanction de blocage. |

#### Exemple d'Erreur 410 Gone :
```json
{
  "message": "Cette publication a été supprimée définitivement par son auteur.",
  "error": "POST_GONE",
  "resource_type": "post",
  "resource_id": "12"
}
```

#### Exemple d'Erreur 423 Locked :
```json
{
  "message": "Cette ressource est temporairement verrouillée ou indisponible suite à une décision de modération.",
  "error": "POST_LOCKED",
  "resource_type": "post",
  "resource_id": "12"
}
```

---

## 4. Schémas de Base de Données

1. **`legal_documents`** :
   - `id`, `slug`, `version`, `locale`, `title`, `content_markdown`, `effective_date`, `is_published`, `published_at`, `is_mandatory`, `created_by`, timestamps.
   - Contrainte unique : `['slug', 'version', 'locale']`.
   - Index de recherche : `['slug', 'locale', 'is_published', 'effective_date']`.

2. **`terms_acceptances`** :
   - `id`, `user_id`, `legal_document_id`, `version`, `document_slug`, `locale`, `ip_address`, `user_agent`, `accepted_at`, timestamps.
   - Index : `['user_id', 'document_slug', 'version']`.

3. **`short_links`** :
   - `id`, `code` (unique, 16 car.), `target_type`, `target_id`, `creator_id`, `clicks_count`, `expires_at`, timestamps.
   - Index : `['target_type', 'target_id']`.

4. **Colonnes ajoutées à la table `users`** :
   - `terms_accepted_at` (timestamp, nullable).
   - `accepted_terms_version` (varchar(32), nullable).

---

## 5. Commandes de Test et de Validation

```bash
# 1. Tests spécifiques du CMS légal, acceptation CGU, liens universels et erreurs sémantiques :
php artisan test tests/Feature/Legal/LegalDocumentCmsTest.php tests/Feature/Legal/TermsAcceptanceTest.php tests/Feature/Content/UniversalLinkResolverTest.php tests/Feature/Content/SemanticErrorHandlingTest.php --compact

# 2. Exécution conjointe des suites Legal et Content :
php artisan test tests/Feature/Legal tests/Feature/Content --compact

# 3. Contrôle de style et formatage Laravel Pint :
vendor/bin/pint --format agent
```
