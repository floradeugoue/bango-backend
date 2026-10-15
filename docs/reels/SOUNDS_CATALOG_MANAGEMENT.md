# BANGO — Administration et Gestion du Catalogue Audio des Reels (Module RL / CR2)

Ce document décrit l'architecture, la structure de données, les règles métier et les spécifications d'API relatives à la constitution et à l'administration du **catalogue audio officiel** utilisable dans Studio Reel.

---

## 1. Principes Fondamentaux & Conformité Juridique

Le catalogue musical de BANGO repose sur une politique stricte de droits d'auteur :
1. **Constitution manuelle et contrôlée :** Aucun service musical externe (Spotify, Deezer, etc.) n'est interrogé. Les musiques sont sélectionnées, vérifiées et importées manuellement par l'équipe BANGO.
2. **Autorisations préalables obligatoires :** Seules les œuvres disposant d'une licence explicite autorisant le téléchargement, l'hébergement serveur, la redistribution au sein de l'application et la synchronisation avec des vidéos créées par les utilisateurs peuvent être intégrées.
3. **Condition stricte de publication :** Un son ne peut jamais être publié si son statut de droits n'est pas expressément validé (`rights_status: 'verified'`). Tout déclassement de droits entraîne la dépublication immédiate du son.
4. **Continuité des Reels existants :** La désactivation ou le retrait d'un son du catalogue n'entraîne **jamais** la suppression du fichier physique si celui-ci est utilisé par des Reels déjà publiés. Le son est marqué `is_removed = true`, ce qui le retire de la sélection pour les futures vidéos tout en préservant la lecture sur les publications passées.

---

## 2. Modèle de Données (`sounds`)

La table PostgreSQL `sounds` comprend les champs suivants :

| Champ | Type | Description |
|---|---|---|
| `id` | `VARCHAR(255)` PK | Identifiant textuel unique (slug prévisible ou `orig:<reel_id>`) |
| `title` | `VARCHAR(255)` | Titre de l'œuvre musicale |
| `artist` | `VARCHAR(255)` | Nom de l'artiste ou du créateur |
| `genre` | `VARCHAR(100)` | Genre musical (`afro`, `makossa`, `coupe-decale`, `gospel`, `ambiance`, `highlife`, `amapiano`, `traditional`) |
| `audio_url` | `VARCHAR(255)` | Chemin relatif de stockage (`sounds/catalogue/audio_uuid.mp3`) |
| `duration_seconds` | `INTEGER` | Durée du morceau (en secondes) |
| `provenance` | `VARCHAR(255)` | Source ou partenaire fournisseur (ex: *« Studio BANGO »*, *« FMA »*, etc.) |
| `license_type` | `VARCHAR(100)` | Typologie de licence (`proprietary_acquired`, `cc_by`, `cc_by_sa`, `cc0`, `public_domain`) |
| `license_name` | `VARCHAR(255)` | Intitulé officiel de la licence |
| `license_url` | `VARCHAR(1000)` | Lien vers le contrat de licence ou le texte juridique |
| `license_terms` | `TEXT` | Obligations légales et mentions d'attribution |
| `usage_restrictions` | `TEXT` | Restrictions particulières (ex: exclusion commerciale, territoires) |
| `rights_status` | `VARCHAR(50)` | Statut de validation administrative (`pending`, `verified`, `rejected`) |
| `is_published` | `BOOLEAN` | État de publication (visible ou masqué du catalogue public) |
| `published_at` | `TIMESTAMP` | Horodatage de publication |
| `base_uses` | `INTEGER` | Compteur initial d'utilisations |
| `uses_count` | `INTEGER` | Compteur incrémenté dynamiquement par les Reels publiés |
| `is_original` | `BOOLEAN` | `true` s'il s'agit d'un son original extrait d'une vidéo utilisateur |
| `original_reel_id` | `VARCHAR(255)` | Référence du Reel d'origine (si son original) |
| `is_locked` | `BOOLEAN` | Verrouillage de la réutilisation par l'auteur original |
| `is_removed` | `BOOLEAN` | Retrait logique du catalogue (sans destruction du fichier) |
| `territories` | `JSONB` | Liste optionnelle de codes pays autorisés |

---

## 3. Organisation du Stockage Physique

Tous les fichiers audio du catalogue sont stockés dans le sous-dossier réservé :
```text
storage/app/public/
└── sounds/
    ├── catalogue/      <-- Fichiers audio officiels du catalogue
    ├── originals/      <-- Sons originaux générés par les créateurs
    └── temp/           <-- Fichiers temporaires d'encodage
```

* **Nommage :** `audio_{uuid}.{extension}` (MP3, WAV, AAC, OGG, M4A).
* **Taille maximale :** 30 Mo par fichier.
* **Résolution d'accès :** Gérée par le service central `MediaStorageService`.
* **Disque actif :** `public` en local (`storage/app/public`, accessible via le lien symbolique `public/storage`).

---

## 4. Spécifications des API Back-Office (`/api/admin/sounds`)

Toutes ces routes exigent une authentification administrateur via le token Bearer Passport et le rôle `admin` :
```http
Authorization: Bearer <admin_token>
```

### 4.1. Lister les sons du catalogue
* **Route :** `GET /api/admin/sounds`
* **Query Params :**
  * `search` ou `q` : Recherche textuelle dans le titre, l'artiste, la provenance ou la licence.
  * `genre` : Filtrer par genre musical.
  * `rights_status` : Filtrer par statut de droits (`pending`, `verified`, `rejected`).
  * `is_published` : Filtrer par visibilité (`true` / `false`).
  * `is_removed` : Filtrer les sons retirés ou actifs.
  * `sort_by` : `latest` (par défaut) ou `popular`.
  * `per_page` : Nombre de résultats par page (défaut: 25).
* **Réponse (`200 OK`) :** Collection paginée de `SoundResource`.

### 4.2. Importer un nouveau son dans le catalogue
* **Route :** `POST /api/admin/sounds`
* **Format :** `multipart/form-data`
* **Champs requis :**
  * `title` (string, max 255)
  * `artist` (string, max 255)
  * `genre` (string, max 100)
  * `audio_file` ou `file` (fichier audio : MP3, WAV, AAC, OGG, M4A — max 30 Mo)
* **Champs optionnels :**
  * `duration_seconds` (integer, 1 à 600)
  * `provenance` (string, max 255)
  * `license_type` (string, ex: `proprietary_acquired`, `cc_by`)
  * `license_name` (string)
  * `license_url` (url)
  * `license_terms` (string)
  * `usage_restrictions` (string)
  * `rights_status` (`pending`, `verified`, `rejected` — défaut: `verified`)
  * `is_published` (`true` / `false` — **refusé en 422 si `rights_status !== 'verified'`**)
  * `base_uses` (integer)
* **Réponse (`201 Created`) :** Objet du son créé avec son chemin et son URL publique.

### 4.3. Consulter les détails d'un son
* **Route :** `GET /api/admin/sounds/{id}`
* **Réponse (`200 OK`) :** Détail complet incluant les métadonnées de licence et les 10 derniers Reels associés.

### 4.4. Mettre à jour les métadonnées d'un son
* **Route :** `POST /api/admin/sounds/{id}`
* **Format :** `multipart/form-data` ou `application/json`
* **Description :** Permet de modifier le titre, l'artiste, la licence ou de remplacer le fichier physique sans casser les Reels existants.

### 4.5. Activer ou Désactiver la Publication
* **Route :** `PATCH /api/admin/sounds/{id}/publication`
* **Payload :** `{"is_published": true | false}`
* **Règle :** Si `is_published = true` et `rights_status !== 'verified'`, une erreur `422` est retournée.

### 4.6. Mettre à jour le Statut des Droits
* **Route :** `PATCH /api/admin/sounds/{id}/rights`
* **Payload :** `{"rights_status": "pending" | "verified" | "rejected"}`
* **Règle :** Déclasser les droits (`pending` ou `rejected`) désactive automatiquement la publication (`is_published = false`).

### 4.7. Retirer un son du catalogue public
* **Route :** `DELETE /api/admin/sounds/{id}`
* **Description :** Positionne `is_removed = true` et `is_published = false`. Le fichier physique est conservé pour les Reels passés.

### 4.8. Restaurer un son retiré
* **Route :** `POST /api/admin/sounds/{id}/restore`
* **Description :** Réactive le son (`is_removed = false`).

### 4.9. Suppression Définitive (Protégée)
* **Route :** `DELETE /api/admin/sounds/{id}/force`
* **Règle :** Rejetée avec erreur `422` si le son est utilisé par au moins un Reel. Acceptée uniquement si aucun Reel n'y fait référence.

---

## 5. API Utilisateurs (`/api/reels/sounds`)

Pour les créateurs et utilisateurs de l'application mobile :
* `GET /api/reels/sounds` : Ne retourne **que** les sons qui satisfont simultanément :
  * `is_removed = false`
  * `is_published = true`
  * `rights_status = 'verified'`
* Les sons désactivés ou non validés sont strictement invisibles dans le sélecteur Studio Reel.
* Les Reels publiés utilisant un son retiré restent lisibles, avec la mention informative `sound_removed: true`.
