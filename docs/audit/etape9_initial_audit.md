# BANGO — Étape 9 : Audit Initial des Modules SQ, RC, MS et BO

## 1. Contexte et Objectif

Le présent document constitue l'audit préalable obligatoire pour l'Étape 9 de BANGO : finalisation et raccordement de fonctionnalités ciblées dans les modules :
- **SQ — Square** : Liste paginée des personnes ayant aimé une publication (`GET /api/square-cr1/posts/{id}/likers`).
- **RC — Recherche & Découverte** : Recherche plein texte des publications (`GET /api/search/posts?q=...`), recherche de hashtags (`GET /api/search/hashtags?q=...`) et tendances unifiées (`GET /api/trends`).
- **MS — Messagerie privée** : Boîte des demandes de messages provenant d'inconnus (`GET /api/messages/requests`, `POST /api/messages/requests/{id}/accept`, `POST /api/messages/requests/{id}/reject`).
- **BO — Boost & Sponsoring** : Sélection des contenus boostables avec score de surperformance relative (`GET /api/boost/boostable`).

---

## 2. Tableau d'audit comparatif par fonctionnalité

| Module & Fonctionnalité | État constaté | Fichiers et composants existants | Routes et schémas concernés | Écarts par rapport aux exigences | Modifications nécessaires | Tests existants / à créer |
|---|---|---|---|---|---|---|
| **SQ — Likers paginés** | 🟡 **Partiel** | - `PostLikeController`<br>- `PostLikeService`<br>- `PostLike`, `Post`, `User`<br>- `BadgeResolutionService`<br>- `FollowUserResource` | Route actuelle : `POST/DELETE /api/square-cr1/posts/{post}/like`<br>Schéma : `post_likes`, `posts`, `users`, `user_blocks` | Pas d'endpoint `GET /posts/{id}/likers`. Pas de pagination des likers, ni filtrage des bloqués/suspendus, ni exposition unifiée des badges de confiance (`SQ-14`). | 1. Ajouter méthode `likers()` dans `PostLikeController` (ou controller dédié).<br>2. Exposer `PostLikerResource` ou réutiliser `FollowUserResource` avec badges.<br>3. Filtrer comptes supprimés/suspendus et blocages réciproques.<br>4. Route `GET /api/square-cr1/posts/{post}/likers`. | Tests existants : `PostLikesTest.php`.<br>À créer : `tests/Feature/Square/PostLikersTest.php`. |
| **RC — Recherche plein texte posts** | 🔴 **Absent** | - Modèle `Post`<br>- `PostResource`<br>- Scopes existants (`scopeActive`) | Pas de route de recherche de posts.<br>Schéma : `posts` (`body`, `place_label`, `user_id`, `page_id`). | Recherche textuelle dans le corps des publications inexistante. Pas d'index textuel PostgreSQL ni tri par pertinence. | 1. Créer migration pour index PostgreSQL (tsvector ou pg_trgm / ILIKE selon compatibilité).<br>2. Créer `PostSearchController` et `PostSearchService`.<br>3. Valider `q` (min 2, max 100), pagination stable.<br>4. Exclure posts archivés/supprimés et auteurs bloqués. | À créer : `tests/Feature/Search/PostSearchTest.php`. |
| **RC — Recherche hashtags** | 🟡 **Partiel** | - `HashtagSearchController`<br>- `Hashtag`<br>- `HashtagSearchResultResource` | Route actuelle : `GET /api/search/hashtags/trending`<br>Schéma : `hashtags`, `post_hashtag` | L'endpoint standard `GET /api/search/hashtags?q=...` n'est pas mappé directement ; le contrôleur existant possède déjà une méthode `trending` qui filtre par `q`. | 1. Mapper `GET /api/search/hashtags` sur `HashtagSearchController@index` ou `trending`.<br>2. Valider et normaliser la recherche (gestion du `#` et minuscules).<br>3. Pagination si volume supérieur. | À créer : `tests/Feature/Search/HashtagSearchTest.php`. |
| **RC — Tendances unifiées** | 🔴 **Absent** | - `Hashtag` (`posts_count`)<br>- `Sound` (`uses_count`, `base_uses`, `scopeAvailableForUsers`)<br>- `User` (`status`, `account_type`, `badge`) | Aucune route `GET /api/trends` existante.<br>Schéma : `hashtags`, `sounds`, `users`, `reels`, `posts`. | Pas d'agrégation unifiée combinant top hashtags, musiques du moment et créateurs émergents (`RC-11`). | 1. Créer `TrendsController` avec endpoint unifié `GET /api/trends`.<br>2. Agréger les 3 catégories réelles avec fenêtre temporelle et métriques effectives.<br>3. Mettre en cache court avec invalidation propre. | À créer : `tests/Feature/Search/TrendsTest.php`. |
| **MS — Demandes de messages** | 🟡 **Partiel** | - `Conversation`, `ConversationMember`, `Message`<br>- `MessageThreadService`<br>- `MessageThreadController`<br>- `ConversationResource` | Schéma : `conversations`, `conversation_members`, `messages`, `user_blocks`, `follows`. | Les conversations directes sont actuellement listées indistinctement dans `listUserThreads`. Pas de séparation pour les inconnus (`is_request` / `status = pending`), pas d'endpoints `accept` ni `reject`. | 1. Ajouter gestion de statut de demande (`status` : active/pending/rejected) sur les conversations directes avec un inconnu (non suivi en retour).<br>2. Créer `MessageRequestController` (`index`, `accept`, `reject`).<br>3. Isolation stricte par utilisateur, atomicité et idempotence.<br>4. Émission des événements Reverb si pertinent. | Tests existants : `MessageThreadTest.php`.<br>À créer : `tests/Feature/Message/MessageRequestsTest.php`. |
| **BO — Contenus boostables & percentile** | 🟡 **Partiel** | - `CampaignEligibilityService`<br>- `Campaign`<br>- `Post`, `Reel`<br>- `BadgeResolutionService`<br>- `Page` | Route absente : `GET /api/boost/boostable`.<br>Schéma : `posts`, `reels`, `pages`, `campaigns`, `subscriptions`. | Impossible pour un utilisateur ou une Page Pro de lister ses contenus éligibles au boost avec leur score relatif de surperformance. | 1. Créer `BoostableContentController` (`GET /api/boost/boostable`).<br>2. Filtrer les contenus appartenant à l'utilisateur ou à sa Page Pro.<br>3. Appliquer la Gate `boost.start` et les contrôles SUB/BADGE/KYC.<br>4. Calculer le percentile réel de surperformance par rapport aux autres contenus du même auteur.<br>5. Respecter la non-mutation financière (lecture seule). | À créer : `tests/Feature/Boost/BoostableContentTest.php`. |

---

## 3. Plan d'exécution séquentiel

1. **Phase A — SQ : Likers paginés**
   - Implémentation dans `PostLikeController@likers` avec `PostLikerResource` (badges, vérification, pagination).
   - Tests `PostLikersTest.php`.
2. **Phase B — RC : Recherche plein texte posts**
   - Migration index texte PostgreSQL, `PostSearchController`, `PostSearchService`.
   - Tests `PostSearchTest.php`.
3. **Phase C — RC : Hashtags et Tendances unifiées**
   - Raccordement `GET /api/search/hashtags` et création `GET /api/trends`.
   - Tests `HashtagAndTrendsTest.php`.
4. **Phase D — MS : Demandes de messages**
   - Migration colonne/statut de conversation, `MessageRequestController` (`index`, `accept`, `reject`).
   - Tests `MessageRequestsTest.php`.
5. **Phase E — BO : Contenus boostables et score percentile**
   - `BoostableContentController`, service de calcul statistique du percentile, intégration avec `CampaignEligibilityService`.
   - Tests `BoostableContentTest.php`.
6. **Bouclage final** : Analyse statique, Pint, suite Pest complète, documentation.
