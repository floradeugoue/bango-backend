# AUDIT COMPLET FRONTEND — MODULE CR1 SQUARE BANGO

## 1. Contexte et Périmètre

L'audit a été réalisé directement sur le code source de l'application mobile BANGO (`C:\Users\DEUGOUE Floriane\Documents\GitHub\Bango`).
Le module Square correspond au fil social et communautaire de BANGO, intégrant la création de publications (posts), carrousels photos/vidéos, retouches, tags de personnes, hashtags, lieux géographiques, gestion de l'audience et options de publication (commentaires, partages, masquage des likes), ainsi que les actions d'administration post-publication (SQ-15).

---

## 2. Fichiers et Composants Clés Analysés

| Fichier Frontend | Rôle Fonctionnel |
| :--- | :--- |
| `docs/SPECS/CR.md` | Spécifications complètes CR-01 à CR-48 (Création, retouches, carrousel, publication, drafts, reprise) |
| `docs/API_CONTRACTS.md` | Contrats d'API pour SQ-15 (actions sur posts), signalement, feed, notifications |
| `lib/api/compose.ts` | Types TypeScript pour la composition : `Draft`, `DraftKind`, `ContentTag`, `ContentPlace`, options (`commentsBy`, `allowShare`, `hideLikes`, `asPageId`) |
| `lib/api/publishing.ts` | Gestionnaire de téléversement et publication asynchrone, reprise sur coupure réseau (`PublishJob`, `recoverPublishing`) |
| `lib/api/square.ts` | Services API du fil Square, structure `Post`, `PostAudience`, actions SQ-15 (`updateCaption`, `setCommentsClosed`, `archivePost`, `deletePost`, `setAudience`) |
| `app/caption-edit.tsx` | Écran d'édition de la légende avec limite max à 2 200 caractères et marquage "modifié" |
| `components/square/PostOwnerSheet.tsx` | Feuille d'actions du propriétaire du post : modifier légende, modifier audience, fermer/ouvrir commentaires, booster, archiver, supprimer |
| `app/create/pick.tsx`, `order.tsx`, `edit.tsx`, `tag.tsx`, `place.tsx`, `settings.tsx`, `preview.tsx` | Flux étape par étape de création Square |

---

## 3. Contrats de Données & Structures TypeScript

### 3.1. Structure du Post (`lib/api/square.ts`)
```typescript
export type PostAudience = 'public' | 'followers' | 'neighbourhood';

export interface Post {
  id: string;
  authorId: string;
  authorName: string;
  authorAvatar?: string;
  authorVerified?: boolean;
  authorBadge?: string;
  authorMeta?: string;
  body: string;
  media?: string;
  mediaType?: 'photo' | 'video';
  photo?: string;
  photos?: string[];
  aspect?: number;
  place?: string;
  likes: number;
  comments: number;
  liked?: boolean;
  commentsClosed?: boolean;
  mine?: boolean;
  edited?: boolean;
  at?: string;
  commentsBy?: 'everyone' | 'followers' | 'nobody';
  hideLikes?: boolean;
  shareable?: boolean;
  tagged?: string[];
  tags?: {
    accountId: string;
    handle: string;
    x: number;
    y: number;
    mediaIndex: number;
  }[];
}
```

### 3.2. Payload de Création (`lib/api/compose.ts` & `lib/api/publishing.ts`)
```typescript
{
  body?: string; // Max 2200 caractères
  media_ids?: string[]; // IDs des médias déjà uploadés ou array d'objets médias
  media?: Array<{
    url: string;
    type: 'photo' | 'video';
    mime_type?: string;
    file_size?: number;
    width?: number;
    height?: number;
    duration?: number;
    display_order: number;
    edit_metadata?: Record<string, any>; // filtre, crop, rotation
  }>;
  audience: 'public' | 'followers' | 'neighbourhood'; // Défaut: 'public'
  comments_by: 'everyone' | 'followers' | 'nobody'; // Défaut: 'everyone'
  comments_closed?: boolean; // Défaut: false
  allow_share?: boolean; // Défaut: true
  hide_likes?: boolean; // Défaut: false
  as_page_id?: string | null;
  place?: {
    label: string;
    detail?: string;
    city?: string;
    neighbourhood?: string;
  } | null;
  tags?: Array<{
    account_id: string;
    handle: string;
    x: number; // entre 0.0 et 1.0
    y: number; // entre 0.0 et 1.0
    media_index: number; // index dans le carrousel
  }>;
  idempotency_key?: string; // UUID v4 pour reprise / anti-doublon
}
```

### 3.3. Actions Post-Publication SQ-15 (`docs/API_CONTRACTS.md` & `lib/api/square.ts`)
- **Modification de légende** :
  - `PATCH /api/v1/posts/{id}/caption`
  - Body : `{ caption: string }` ou `{ body: string }` (max 2 200 caractères).
  - Effet : met à jour le texte et passe `is_edited = true`.
- **Modification d'audience** :
  - `PATCH /api/v1/posts/{id}/audience`
  - Body : `{ audience: 'public' | 'followers' | 'neighbourhood' }`.
- **Statut des commentaires** :
  - `PATCH /api/v1/posts/{id}/comments-status`
  - Body : `{ comments_closed: boolean }` ou `{ comments_by: 'everyone' | 'followers' | 'nobody' }`.
- **Archivage / Désarchivage** :
  - `POST /api/v1/posts/{id}/archive`
  - `POST /api/v1/posts/{id}/unarchive`
  - Effet : masque le post du fil public, visible uniquement par l'auteur dans ses archives.
- **Suppression** :
  - `DELETE /api/v1/posts/{id}`
  - Effet : suppression en cascade (ou soft delete) sécurisée réservée à l'auteur ou admin.

### 3.4. Téléversement de Médias (`lib/api/publishing.ts` & CR-02, CR-04, CR-12, CR-13)
- Endpoint : `POST /api/v1/media/upload` (Multipart form-data).
- Champs : `file` (image/jpeg, image/png, image/webp, video/mp4, video/quicktime), `kind` ('photo' | 'video' | 'square').
- Réponse attendue :
  ```json
  {
    "id": "uuid-or-ulid",
    "url": "https://.../storage/posts/media_xxx.jpg",
    "type": "photo",
    "mime_type": "image/jpeg",
    "file_size": 245100,
    "width": 1080,
    "height": 1350
  }
  ```
- Reprise : les brouillons restent locaux dans AsyncStorage (CR-14). Les jobs d'upload stockent les états intermédiaires côté client (`working`, `failed`, `done`) avec un `jobId` et une clé d'idempotence pour la publication finale.

### 3.5. Recherche Contextuelle (CR-08, CR-09, CR-07)
- **Personnes** : `GET /api/v1/search/people?q={query}` (résultats : `id`, `name`, `handle`, `avatar_url`, `verified`, `badge`).
- **Hashtags tendances** : `GET /api/v1/search/hashtags/trending?q={query}` (résultats : `name`, `posts_count`).
- **Lieux** : `GET /api/v1/search/places?q={query}` (recherche sur les quartiers, villes et pages pro réelles).

---

## 4. Points d'Attention & Écarts Identifiés
1. **Nommage `body` vs `caption`** : Le frontend utilise `body` dans l'objet `Post` et `caption` dans l'endpoint `PATCH /caption`. Le backend doit supporter les deux aliases en entrée.
2. **Longueur maximale** : 2 200 caractères (conforme à Instagram/Square et à `app/caption-edit.tsx`).
3. **Coordonnées de tags** : `x` et `y` sont des flottants normalisés `0.0 <= val <= 1.0`.
4. **Idempotence** : Transmission d'une clé d'idempotence (`idempotency_key`) évitant la duplication de post en cas de retry réseau du `PublishJob`.
