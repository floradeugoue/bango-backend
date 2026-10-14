# SCHÉMA DE BASE DE DONNÉES — MODULE CR1 SQUARE BANGO

## 1. Vue d'Ensemble
Le schéma relationnel du module Square repose sur 5 tables principales :
1. `posts` : Table principale des publications.
2. `post_media` : Médias associés au carrousel (1 à 10 éléments).
3. `post_tags` : Personnes identifiées sur chaque média photo.
4. `hashtags` : Registre des hashtags uniques et normalisés.
5. `post_hashtag` : Table pivot reliant publications et hashtags.

---

## 2. Définition des Tables et Colonnes

### 2.1. Table `posts`
| Colonne | Type | Nullable | Index / Contraintes | Description |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint unsigned` | Non | Clé primaire | Identifiant unique de la publication |
| `user_id` | `bigint unsigned` | Non | Foreign Key -> `users(id)` ON DELETE CASCADE | Auteur de la publication |
| `page_id` | `bigint unsigned` | Oui | Index | Page pro si publication sous entité |
| `body` | `text` | Oui | | Légende / texte (max 2 200 caractères) |
| `audience` | `varchar` | Non | Index | `'public'`, `'followers'`, `'neighbourhood'` |
| `comments_by` | `varchar` | Non | | `'everyone'`, `'followers'`, `'nobody'` |
| `comments_closed` | `boolean` | Non | Défaut `false` | Verrouillage des commentaires |
| `allow_share` | `boolean` | Non | Défaut `true` | Autorisation de repartage |
| `hide_likes` | `boolean` | Non | Défaut `false` | Masquage du nombre de mentions j'aime |
| `is_edited` | `boolean` | Non | Défaut `false` | Indicateur de modification de légende |
| `is_archived` | `boolean` | Non | Index, Défaut `false` | Statut d'archivage |
| `place_label` | `varchar` | Oui | | Libellé du lieu |
| `place_detail` | `varchar` | Oui | | Complément d'adresse ou ville |
| `place_city` | `varchar` | Oui | Index | Ville associée |
| `place_neighbourhood`| `varchar` | Oui | Index | Quartier associé |
| `idempotency_key` | `varchar` | Oui | Unique | Clé d'idempotence réseau |
| `likes_count` | `integer unsigned` | Non | Défaut `0` | Compteur dénormalisé de likes |
| `comments_count`| `integer unsigned` | Non | Défaut `0` | Compteur dénormalisé de commentaires |
| `shares_count` | `integer unsigned` | Non | Défaut `0` | Compteur dénormalisé de partages |
| `deleted_at` | `timestamp` | Oui | Soft deletes | Date de suppression logique |
| `created_at` | `timestamp` | Non | Index composé | Date de publication |
| `updated_at` | `timestamp` | Non | | Date de mise à jour |

---

### 2.2. Table `post_media`
| Colonne | Type | Nullable | Contraintes | Description |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint unsigned` | Non | Clé primaire | Identifiant du média |
| `post_id` | `bigint unsigned` | Non | FK -> `posts(id)` ON DELETE CASCADE | Publication parente |
| `media_url` | `varchar` | Non | | URL publique de stockage |
| `media_type` | `varchar` | Non | Défaut `'photo'` | `'photo'` ou `'video'` |
| `mime_type` | `varchar` | Oui | | Type MIME réel (`image/jpeg`, etc.) |
| `file_size` | `bigint unsigned` | Oui | | Taille du fichier en octets |
| `width` | `integer unsigned`| Oui | | Largeur en pixels |
| `height` | `integer unsigned`| Oui | | Hauteur en pixels |
| `duration` | `integer unsigned`| Oui | | Durée en secondes (vidéos) |
| `display_order` | `smallint unsigned`| Non | Défaut `0`, Index composé | Ordre dans le carrousel (0 = couverture) |
| `edit_metadata` | `jsonb / json` | Oui | | Métadonnées de retouche (filtres, crop) |
| `created_at` | `timestamp` | Non | | |
| `updated_at` | `timestamp` | Non | | |

---

### 2.3. Table `post_tags`
| Colonne | Type | Nullable | Contraintes | Description |
| :--- | :--- | :--- | :--- | :--- |
| `id` | `bigint unsigned` | Non | Clé primaire | Identifiant du tag |
| `post_media_id`| `bigint unsigned` | Non | FK -> `post_media(id)` ON DELETE CASCADE | Média photo ciblé |
| `user_id` | `bigint unsigned` | Non | FK -> `users(id)` ON DELETE CASCADE | Compte utilisateur identifié |
| `x` | `decimal(5,4)` | Non | Entre `0.0000` et `1.0000` | Coordonnée relative horizontale |
| `y` | `decimal(5,4)` | Non | Entre `0.0000` et `1.0000` | Coordonnée relative verticale |
| `created_at` | `timestamp` | Non | | |
| `updated_at` | `timestamp` | Non | | |

*Contrainte d'unicité* : `unique(['post_media_id', 'user_id'])` (évite de tagguer deux fois le même utilisateur sur la même image).

---

### 2.4. Tables `hashtags` et `post_hashtag`
- `hashtags` :
  - `id` : clé primaire
  - `name` : chaîne exacte (ex: `BangoImmo`)
  - `normalized_name` : minuscule sans diacritiques (ex: `bangoimmo`), index unique
  - `posts_count` : compteur entier dénormalisé
- `post_hashtag` :
  - `post_id` -> `posts(id)` ON DELETE CASCADE
  - `hashtag_id` -> `hashtags(id)` ON DELETE CASCADE
  - `unique(['post_id', 'hashtag_id'])`
