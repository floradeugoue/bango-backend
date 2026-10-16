# BANGO — Documentation Technique Étape 9 : SQ, RC, MS & BO
## Recherche Plein Texte, Social, Demandes de Chat & Contenus Boostables

**Projet :** BANGO Backend (Laravel 13 / PHP 8.4 / PostgreSQL 18.6)  
**Date :** 16 Octobre 2026  
**Statut :** Validé — 100 % opérationnel, testé (36 tests spécifiques / 186 tests modules)

---

## 1. Vue d'Ensemble & Périmètre d'Intervention

L'Étape 9 finalise et raccorde quatre fonctionnalités clés transversales de BANGO :
1. **SQ — Square (`SQ-14`)** : Liste paginée des personnes ayant aimé une publication avec résolution des badges actifs de confiance (`PostLikerResource`).
2. **RC — Recherche Plein Texte (`RC-06`)** : Recherche dans les légendes des publications Square avec pondération et classement par pertinence (`tsvector`, `tsquery`, index GIN PostgreSQL et fallback adaptatif).
3. **RC — Hashtags & Tendances Unifiées (`RC-07`, `RC-11`)** : Recherche paginée de hashtags normalisés et endpoint unifié de tendances agrégeant Top Hashtags, Musiques tendances (RL) et Créateurs émergents.
4. **MS — Demandes de Messages d'Inconnus (`MS-05`)** : Boîte d'attente étanche pour les messages initiés par des personnes non suivies, avec acceptation et rejet atomiques/idempotents.
5. **BO — Sélection de Contenus Boostables & Percentile de Surperformance** : Liste éligible avec vérification des permissions (`can('boost.start')`, badges pro, rôles Page Pro) et calcul statistique déterministe du percentile de performance relative (« Performe mieux que X % de tes contenus »).

---

## 2. Inventaire des Endpoints Ajoutés ou Modifiés

| Module | Méthode | URI | Contrôleur & Action | Authentification | Autorisation / Scope |
| :--- | :--- | :--- | :--- | :--- | :--- |
| **SQ** | `GET` | `/api/square-cr1/posts/{post}/likers` | `PostLikeController@likers` | Optionnelle (Bearer) | `PostPolicy@view` + exclusion des comptes bloqués |
| **RC** | `GET` | `/api/search/posts` | `PostSearchController@search` | Optionnelle (Bearer) | Audience publique ou abonnés autorisés + filtres de blocage |
| **RC** | `GET` | `/api/search/hashtags` | `HashtagSearchController@index` | Public | Tags actifs avec `posts_count > 0` |
| **RC** | `GET` | `/api/trends` | `TrendsController@index` | Public | Fenêtre temporelle 30j (Hashtags, Musiques RL, Créateurs) |
| **MS** | `GET` | `/api/messages/requests` | `MessageRequestController@index` | Requise (Bearer) | Destinataire de conversations `status = 'pending'` |
| **MS** | `POST` | `/api/messages/requests/{id}/accept` | `MessageRequestController@accept` | Requise (Bearer) | `ConversationPolicy@handleRequest` |
| **MS** | `POST` | `/api/messages/requests/{id}/reject` | `MessageRequestController@reject` | Requise (Bearer) | `ConversationPolicy@handleRequest` |
| **BO** | `GET` | `/api/boost/boostable` | `BoostableContentController@index` | Requise (Bearer) | `Gate::authorize('boost.start')` + Badges & Rôles Page Pro |

---

## 3. Détail Technique par Module

### 3.1. SQ — Likers Paginés d'une Publication (`SQ-14`)

- **URI :** `GET /api/square-cr1/posts/{post}/likers?page=1&per_page=20`
- **Contrôleur :** `PostLikeController@likers`
- **Service :** `PostLikeService@getPostLikers`
- **Resource :** `PostLikerResource`
- **Règles Métier & Confidentialité :**
  - Vérification préalable de l'accès au post via `PostPolicy@view`.
  - Exclusion automatique des utilisateurs ayant bloqué ou ayant été bloqués par l'utilisateur demandeur (table `blocks`).
  - Exclusion des comptes suspendus ou soft-deleted.
  - Déduplication garantie (`distinct('users.id')`) et tri déterministe (`post_likes.created_at DESC`, `users.id DESC`).
  - Respect strict des badges de confiance BANGO (`SQ-14`) : le badge n'est retourné que s'il est actif (`is_active = true` et `expires_at > now()`). En cas d'expiration, les métadonnées de badge retournent `badge: null` avec indicateur `has_lapsed_badge: true`.
  - Indicateur relationnel `is_following` pour l'utilisateur connecté.

#### Exemple de Réponse (`200 OK`) :
```json
{
  "data": [
    {
      "id": "9d4e5f6a-...",
      "name": "Jean Dupont",
      "handle": "jeandupont",
      "avatar_url": "https://storage.bango.africa/avatars/jean.jpg",
      "account_type": "pro",
      "badge": {
        "id": "badge-uuid",
        "type": "pro_business",
        "name": "Pro Business",
        "color": "#1E40AF",
        "icon": "shield-check"
      },
      "has_lapsed_badge": false,
      "is_following": true,
      "liked_at": "2026-10-16T15:20:00.000000Z"
    }
  ],
  "links": {
    "first": "https://api.bango.africa/api/square-cr1/posts/1/likers?page=1",
    "last": "https://api.bango.africa/api/square-cr1/posts/1/likers?page=1",
    "prev": null,
    "next": null
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 20,
    "to": 1,
    "total": 1
  }
}
```

---

### 3.2. RC — Recherche Plein Texte des Publications (`RC-06`)

- **URI :** `GET /api/search/posts?q=immobilier+douala&page=1&per_page=20` (alias: `GET /api/square-cr1/search/posts`)
- **Validation (`SearchPostsRequest`) :**
  - `q` : obligatoire, chaîne de 2 à 100 caractères.
  - `page` : entier `>= 1`.
  - `per_page` : entier entre 1 et 50 (défaut : 20).
- **Stratégie PostgreSQL :**
  - Migration : `2026_10_16_170000_add_fulltext_index_to_posts_table.php` ajoutant un index GIN sur `to_tsvector('french', coalesce(body, ''))`.
  - Requête plein texte via `websearch_to_tsquery('french', ?)` permettant de parser les requêtes naturelles sans crash sur caractères spéciaux.
  - Calcul du score de pertinence via `ts_rank(to_tsvector('french', coalesce(body, '')), websearch_to_tsquery('french', ?)) AS search_rank`.
  - Tri par pertinence : `ORDER BY search_rank DESC, created_at DESC`.
  - Fallback automatique en SQLite / environnement sans configuration textuelle : recherche par `LOWER(body) LIKE ?` avec décomposition des termes et pondération par occurrences.
- **Sécurité & Visibilité :**
  - Exclusion des posts archivés (`archived_at IS NOT NULL`) et supprimés (`deleted_at IS NOT NULL`).
  - Filtrage de l'audience : seuls les posts `public` sont visibles par un invité ; les posts `followers` ne sont visibles que si l'utilisateur connecté suit l'auteur.
  - Exclusion des auteurs bloqués.

---

### 3.3. RC — Recherche Hashtags & Tendances Unifiées (`RC-07`, `RC-11`)

#### Recherche de Hashtags (`GET /api/search/hashtags?q=...`)
- Normalisation automatique : suppression du préfixe `#`, passage en minuscules, suppression des caractères spéciaux non autorisés.
- Recherche préfixe et partielle via `LIKE ?%` et `LIKE %?%`.
- Seuls les hashtags rattachés à au moins une publication (`posts_count > 0`) sont retournés.
- Tri décroissant par `posts_count DESC`.

#### Tendances Unifiées (`GET /api/trends`)
Combine trois catégories calculées sur données réelles avec fenêtre glissante de 30 jours :
1. **Top Hashtags** :
   - Hashtags ordonnés par `posts_count DESC`, limités aux hashtags effectivement utilisés.
2. **Musiques & Sons du Moment (RL)** :
   - Réutilise le modèle `Sound` du module Reels.
   - Pistes actives (`status = active`, son non supprimé), triées par popularité d'usage (`use_count DESC`).
3. **Créateurs Émergents** :
   - Utilisateurs actifs ayant publié du contenu récent (Post Square ou Reel) dans les 30 derniers jours.
   - Exclusion des profils privés (`is_private = true`), suspendus ou bloqués.
   - Priorisation des créateurs certifiés (badges professionnels ou abonnements de confiance actifs).
   - Ordonnancement par audience et activité réelle (`followers_count DESC`).

#### Exemple de Réponse (`200 OK`) :
```json
{
  "data": {
    "hashtags": [
      {
        "id": 1,
        "name": "cameroun",
        "tag": "cameroun",
        "posts_count": 450,
        "is_trending": true
      }
    ],
    "trending_sounds": [
      {
        "id": "sound-uuid",
        "title": "Bikutsi Hit",
        "artist": "Artiste Bango",
        "use_count": 128,
        "duration": 30,
        "audio_url": "https://storage.bango.africa/audio/hit.mp3"
      }
    ],
    "emerging_creators": [
      {
        "id": "creator-uuid",
        "name": "Sarah K.",
        "handle": "sarahk",
        "avatar_url": "https://storage.bango.africa/avatars/sarah.jpg",
        "followers_count": 5400,
        "badge": { "type": "verified_creator" }
      }
    ]
  },
  "window": "30_days",
  "generated_at": "2026-10-16T17:15:00.000000Z"
}
```

---

### 3.4. MS — Demandes de Messages d'Inconnus (`MS-05`)

#### Définition Métier d'un « Inconnu »
Dans BANGO, une conversation directe est initiée avec le statut `pending` (demande de message) si et seulement si :
1. La conversation est directe (`is_direct = true`).
2. Aucun contexte d'annonce immobilière n'est rattaché (`listing_id IS NULL`, car les prises de contact pour visite/annonce sont directement autorisées).
3. Le destinataire **ne suit pas** l'initiateur du message (`Follow::where('follower_id', $recipientId)->where('following_id', $senderId)->doesntExist()`).

Si le destinataire suit l'initiateur ou s'il s'agit d'un bien immobilier, la conversation est immédiatement créée avec le statut `active`.

#### Endpoints du Cycle de Vie des Demandes :

1. **`GET /api/messages/requests`**
   - Retourne les conversations où l'utilisateur connecté est destinataire avec `status = 'pending'`.
   - Les conversations `pending` sont masquées de la boîte de réception normale (`GET /api/messages/threads`).
   - Fournit l'expéditeur, le dernier message reçu et l'horodatage.

2. **`POST /api/messages/requests/{id}/accept`**
   - Autorisation : vérifie que l'utilisateur est membre de la conversation et destinataire de la demande (`ConversationPolicy@handleRequest`).
   - Opération atomique : bascule `status = 'active'`.
   - La conversation réintègre automatiquement la boîte de réception principale sans perte de messages.
   - Opération idempotente : si déjà acceptée, retourne `200 OK` avec le fil actif.

3. **`POST /api/messages/requests/{id}/reject`**
   - Autorisation : `ConversationPolicy@handleRequest`.
   - Opération atomique : bascule `status = 'rejected'` et applique un soft-delete sur la conversation (`deleted_at = now()`).
   - L'historique est préservé pour les obligations légales de conservation sans apparaître dans la vue de l'utilisateur.
   - Opération idempotente : un appel ultérieur renvoie `200 OK` avec confirmation du rejet.

---

### 3.5. BO — Contenus Éligibles au Boost & Percentile de Surperformance

- **URI :** `GET /api/boost/boostable?page=1&per_page=15&page_id=...`
- **Contrôleur :** `BoostableContentController@index`
- **Service :** `BoostableContentService@getBoostableContents`
- **Vérifications d'Éligibilité :**
  - Autorisation Gate `boost.start` :
    - Compte personnel : badge professionnel/créateur actif requis.
    - Page Pro : l'utilisateur doit être membre accepté avec rôle `owner`, `admin` ou `editor`.
  - Contenus admissibles :
    - Posts Square : non archivés, non supprimés (`deleted_at IS NULL`), audience compatible.
    - Reels : statut `ReelStatus::Ready` (`ready`), non supprimés.
    - Annonces immo liées : statut non `taken` / non loué.
- **Formule Déterministe du Percentile de Surperformance :**
  - **Score d'engagement unitaire :**
    $$\text{Score} = \text{vues} \times 1 + \text{likes} \times 2 + \text{commentaires} \times 3 + \text{partages} \times 5$$
  - **Population de référence :** L'ensemble des contenus comparables de l'auteur (ou de la Page Pro) publiés sur la plateforme.
  - **Condition de calcul :** Au minimum 3 contenus publiés avec métriques non nulles. En deçà, l'API retourne explicitement `percentile: null` et `performance_label: "Données insuffisantes pour établir une comparaison"`.
  - **Calcul standardisé du Percentile (avec gestion des égalités) :**
    $$\text{Percentile} = \frac{\text{Strictement inférieurs} + 0.5 \times \text{Égaux (hors contenu)}}{\text{Total contenus} - 1} \times 100$$
  - **Libellé produit :** `« Performe mieux que X % de tes contenus »`.
  - **Innocuité financière :** Cet endpoint de consultation ne débite aucun crédit BANGO, ne contacte aucune passerelle Mobile Money et n'instancie aucune campagne publicitaire en base de données.

#### Exemple de Réponse (`200 OK`) :
```json
{
  "data": [
    {
      "id": "post-uuid",
      "type": "post",
      "caption": "Nouvel arrivage dans notre boutique !",
      "thumbnail_url": "https://storage.bango.africa/posts/thumb.jpg",
      "created_at": "2026-10-15T10:00:00.000000Z",
      "metrics": {
        "views_count": 1200,
        "likes_count": 85,
        "comments_count": 14,
        "shares_count": 6,
        "engagement_score": 1442
      },
      "outperformance": {
        "percentile": 85,
        "label": "Performe mieux que 85 % de tes contenus",
        "is_top_performer": true
      },
      "is_eligible_for_boost": true
    }
  ],
  "meta": {
    "total_boostable": 1,
    "current_page": 1,
    "per_page": 15
  }
}
```

---

## 4. Migrations & Schémas PostgreSQL Déployés

1. **`database/migrations/2026_10_16_170000_add_fulltext_index_to_posts_table.php`**
   - Crée l'index GIN PostgreSQL :
     ```sql
     CREATE INDEX IF NOT EXISTS posts_body_fulltext_idx 
     ON posts USING gin(to_tsvector('french', coalesce(body, '')));
     ```
   - Rollback propre avec `DROP INDEX IF EXISTS posts_body_fulltext_idx`.

2. **`database/migrations/2026_10_16_171000_add_status_to_conversations_table.php`**
   - Ajoute la colonne `status` (`varchar(20) DEFAULT 'active'`) avec index sur `conversations`.
   - Valeurs autorisées : `'active'`, `'pending'`, `'rejected'`.

---

## 5. Synthèse des Tests Automatisés (Pest)

Les tests ont été exécutés avec succès sans aucune régression :

| Fichier de Test | Scénarios Couverts | Nombre de Tests | Résultat |
| :--- | :--- | :---: | :---: |
| `tests/Feature/Square/PostLikersTest.php` | Likers paginés, badges actifs vs expirés, blocages, posts inaccessibles, déduplication | 8 | 🟢 8/8 passés |
| `tests/Feature/Search/PostSearchTest.php` | Recherche plein texte, pertinence, requêtes courtes/vides, audience, filtres de blocage | 8 | 🟢 8/8 passés |
| `tests/Feature/Search/TrendsAndHashtagSearchTest.php` | Recherche hashtags avec préfixe/normalisation, tendances unifiées (3 catégories), fenêtre 30j | 5 | 🟢 5/5 passés |
| `tests/Feature/Message/MessageRequestsTest.php` | Création de conversation pending pour inconnu, boîte des demandes, acceptation, rejet, idempotence | 8 | 🟢 8/8 passés |
| `tests/Feature/Boost/BoostableContentTest.php` | Éligibilité posts/reels, interdiction sans badge/droits, calcul déterministe du percentile, données insuffisantes | 7 | 🟢 7/7 passés |
| **Suite Totale Modules (SQ, RC, MS, BO)** | **Tous les tests fonctionnels régressifs et d'intégration** | **186** | **🟢 186/186 passés** |

---

## 6. Commandes d'Exécution & Vérification

```bash
# 1. Exécution des tests spécifiques de l'Étape 9 :
php artisan test tests/Feature/Square/PostLikersTest.php tests/Feature/Search/PostSearchTest.php tests/Feature/Search/TrendsAndHashtagSearchTest.php tests/Feature/Message/MessageRequestsTest.php tests/Feature/Boost/BoostableContentTest.php --compact

# 2. Exécution de l'ensemble des modules SQ, RC, MS et BO :
php artisan test tests/Feature/Square tests/Feature/Search tests/Feature/Message tests/Feature/Boost --compact

# 3. Contrôle de style Pint :
vendor/bin/pint --format agent
```
