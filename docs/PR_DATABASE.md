# BANGO — Schéma de Base de Données PostgreSQL Module PR (Profil)

Ce document détaille la structure des tables, colonnes, types de données, contraintes, index et relations PostgreSQL implémentées pour le module **PR (Profil)**.

---

## 🗄️ 1. Évolution de la Table Principale `users`

La table `users` existante conserve l'ensemble des données d'authentification et intègre les champs spécifiques au profil pour éviter toute jointure inutile lors des requêtes fréquentes de session :

```sql
ALTER TABLE users ADD COLUMN is_private BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD COLUMN privacy_settings JSONB NULL;
ALTER TABLE users ADD COLUMN badge VARCHAR(50) DEFAULT 'none';
ALTER TABLE users ADD COLUMN verification_lapsed BOOLEAN DEFAULT FALSE;
ALTER TABLE users ADD COLUMN restricted_until TIMESTAMP NULL;
```

### Détail des Colonnes Utilisées par le Module PR

| Colonne | Type PostgreSQL | Nullable | Valeur par défaut | Description & Rôle Métier |
|---|---|---|---|---|
| `id` | `BIGSERIAL` / `BIGINT` | NON | Auto-incrément | Clé primaire unique |
| `name` | `VARCHAR(255)` | NON | - | Nom civil de base / compte |
| `display_name` | `VARCHAR(255)` | OUI | `NULL` | Nom affiché publiquement sur le profil |
| `handle` | `VARCHAR(255)` | NON | - | Pseudonyme public unique (`unique:users,handle`), insensible à la casse |
| `avatar_url` | `VARCHAR(255)` | OUI | `NULL` | URL publique de l'image de profil |
| `bio` | `TEXT` | OUI | `NULL` | Présentation (biographie, max 500 car., 150 côté écran) |
| `birth_date` | `DATE` | OUI | `NULL` | Date de naissance (validation $\ge 18$ ans) |
| `gender` | `VARCHAR(50)` | OUI | `NULL` | Genre (`woman`, `man`, `other`, `undisclosed`) |
| `gender_hidden` | `BOOLEAN` | NON | `FALSE` | Interrupteur : masquer le genre sur le profil public (AU-07) |
| `is_private` | `BOOLEAN` | NON | `FALSE` | Compte privé (ST-14 / PR-08) : requiert approbation des abonnements |
| `privacy_settings` | `JSON` / `JSONB` | OUI | `NULL` | Réglages d'audience (`seePosts`, `commentPosts`, `messageMe`, `tagMe`, `onlineStatus`, `readReceipts`) |
| `badge` | `VARCHAR(50)` | NON | `'none'` | Badge métier (`none`, `landlord`, `agent`, `double`, `page`) |
| `verification_lapsed` | `BOOLEAN` | NON | `FALSE` | EDGE-09 : Vérifié par le passé, vérification documentaire échue |
| `restricted_until` | `TIMESTAMP` | OUI | `NULL` | SUP-21 : Restriction temporaire de publication publique |
| `country_code` | `VARCHAR(2)` | OUI | `NULL` | Code ISO du pays (ex: `'CM'`, `'CI'`, `'SN'`, `'FR'`) |
| `country_id` | `BIGINT` | OUI | `NULL` | Clé étrangère vers `countries(id)` |
| `city` | `VARCHAR(255)` | OUI | `NULL` | Nom de la ville |
| `city_id` | `BIGINT` | OUI | `NULL` | Clé étrangère vers `cities(id)` |
| `neighbourhood` | `VARCHAR(255)` | OUI | `NULL` | Nom du quartier |
| `neighbourhood_id` | `BIGINT` | OUI | `NULL` | Clé étrangère vers `neighbourhoods(id)` |
| `deleted_at` | `TIMESTAMP` | OUI | `NULL` | Soft delete pour rétractation de 30 jours |

---

## 👥 2. Table `follows` (Abonnements & Réseau Social)

Gère les relations de suivi unidirectionnelles entre utilisateurs :

```sql
CREATE TABLE follows (
    id BIGSERIAL PRIMARY KEY,
    follower_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    following_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT uq_follows_follower_following UNIQUE (follower_id, following_id)
);

CREATE INDEX idx_follows_follower ON follows(follower_id);
CREATE INDEX idx_follows_following ON follows(following_id);
```

### Contraintes & Index PostgreSQL
- **Clé d'unicité `(follower_id, following_id)`** : Empêche formellement les doublons d'abonnements au niveau PostgreSQL.
- **Suppression en cascade (`ON DELETE CASCADE`)** : La suppression d'un utilisateur nettoie automatiquement tous ses liens sans laisser d'orphelins.
- **Index b-tree dédiés** : Permettent des jointures instantanées pour calculer le nombre d'abonnés et d'abonnements sans balayer la table complète.

---

## 📬 3. Table `follow_requests` (Demandes d'Abonnement pour Comptes Privés)

Gère les demandes en attente envoyées aux comptes privés (PR-08 / ST-14) :

```sql
CREATE TABLE follow_requests (
    id BIGSERIAL PRIMARY KEY,
    from_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    to_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT uq_follow_requests_from_to UNIQUE (from_id, to_id)
);

CREATE INDEX idx_follow_requests_to ON follow_requests(to_id);
CREATE INDEX idx_follow_requests_from ON follow_requests(from_id);
```

### Cycle de vie d'une demande
1. L'utilisateur A clique sur « Suivre » devant le compte privé B.
2. Une ligne `(from_id: A, to_id: B, status: 'pending')` est insérée.
3. Si B accepte $\rightarrow$ Une ligne est créée dans `follows` et la ligne `follow_requests` est supprimée.
4. Si B refuse $\rightarrow$ La ligne `follow_requests` est supprimée sans notification.
5. Si B bascule son compte en public $\rightarrow$ Toutes ses demandes `to_id: B` sont converties en `follows` automatiquement.

---

## 🚫 4. Table `user_blocks` (Blocages d'Utilisateurs)

Gère les restrictions de blocage réciproque (PR-09 / SUP-15) :

```sql
CREATE TABLE user_blocks (
    id BIGSERIAL PRIMARY KEY,
    blocker_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    blocked_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    CONSTRAINT uq_user_blocks_blocker_blocked UNIQUE (blocker_id, blocked_id)
);

CREATE INDEX idx_user_blocks_blocker ON user_blocks(blocker_id);
CREATE INDEX idx_user_blocks_blocked ON user_blocks(blocked_id);
```

### Effets de bord transactionnels lors du blocage
Lors d'une insertion dans `user_blocks` :
1. Les entrées dans `follows` sont immédiatement supprimées dans les deux sens : `A suit B` et `B suit A`.
2. Les entrées dans `follow_requests` sont immédiatement supprimées dans les deux sens : `A demande B` et `B demande A`.
3. Le blocage est enregistré.

---

## 🏷️ 5. Table `interests` et Pivot `interest_user`

Table référentielle des 10 centres d'intérêt prédéfinis :

```sql
CREATE TABLE interests (
    id VARCHAR(50) PRIMARY KEY,
    name_fr VARCHAR(100) NOT NULL,
    name_en VARCHAR(100) NOT NULL,
    icon VARCHAR(50) NULL,
    category VARCHAR(50) NULL,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
);

CREATE TABLE interest_user (
    user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    interest_id VARCHAR(50) NOT NULL REFERENCES interests(id) ON DELETE CASCADE,
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL,
    PRIMARY KEY (user_id, interest_id)
);
```

Identifiants officiels seedés : `realestate`, `business`, `sport`, `music`, `fashion`, `food`, `tech`, `travel`, `education`, `entertainment`.
