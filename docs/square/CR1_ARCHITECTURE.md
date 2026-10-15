# ARCHITECTURE TECHNIQUE DU MODULE CR1 SQUARE — BANGO

## 1. Principes Directeurs
- **Séparation des responsabilités** : Contrôleurs minces (orchestration HTTP), FormRequests dédiées (validation), Services métier spécialisés (logique d'affaires, transactions), Policies (autorisation d'accès), Resources (normalisation des réponses JSON).
- **Intégrité et Atomicité** : Toute création de publication est encapsulée dans une transaction de base de données (`DB::transaction`). Si l'attachement d'un média, d'un tag ou d'un hashtag échoue, l'intégralité du post est annulée.
- **Idempotence & Anti-Duplication** : Utilisation d'un index unique sur `idempotency_key` pour garantir qu'un retry réseau du frontend ne génère pas de doublon.
- **Sécurité & Protection IDOR** : Seul l'auteur d'un post (ou un administrateur ayant le rôle adéquat) peut modifier la légende, l'audience, le statut des commentaires, archiver ou supprimer son post (`PostPolicy`).
- **Support des Médias** : Validation stricte des types MIME (photos jpeg, png, webp ; vidéos mp4, mov), limitation de taille (15MB par photo, 100MB par vidéo), calcul automatique des dimensions si nécessaire, stockage sur le disque public sécurisé.

---

## 2. Arborescence Cible

```
app/
├── Enums/
│   └── Square/
│       ├── PostAudience.php          // 'public', 'followers', 'neighbourhood'
│       ├── PostCommentsBy.php        // 'everyone', 'followers', 'nobody'
│       └── PostMediaKind.php         // 'photo', 'video'
├── Http/
│   ├── Controllers/
│   │   └── Api/
│   │       └── V1/
│   │           ├── Square/
│   │           │   ├── PostController.php               // store, show, destroy
│   │           │   ├── PostFeedController.php           // index (feed paginé)
│   │           │   ├── PostCaptionController.php        // update (caption)
│   │           │   ├── PostAudienceController.php       // update (audience)
│   │           │   ├── PostCommentsStatusController.php // update (comments-status)
│   │           │   └── PostArchiveController.php        // archive, unarchive
│   │           ├── Media/
│   │           │   └── MediaUploadController.php        // upload
│   │           └── Search/
│   │               ├── PeopleSearchController.php       // search people
│   │               ├── HashtagSearchController.php      // search trending hashtags
│   │               └── PlaceSearchController.php        // search places
│   ├── Requests/
│   │   └── Api/
│   │       └── V1/
│   │           ├── Square/
│   │           │   ├── CreatePostRequest.php
│   │           │   ├── UpdateCaptionRequest.php
│   │           │   ├── UpdateAudienceRequest.php
│   │           │   └── UpdateCommentsStatusRequest.php
│   │           └── Media/
│   │               └── UploadMediaRequest.php
│   └── Resources/
│       └── Api/
│           └── V1/
│               ├── Square/
│               │   ├── PostResource.php
│               │   ├── PostMediaResource.php
│               │   └── PostTagResource.php
│               └── Search/
│                   ├── PeopleSearchResultResource.php
│                   └── HashtagSearchResultResource.php
├── Models/
│   ├── Post.php
│   ├── PostMedia.php
│   ├── PostTag.php
│   └── Hashtag.php
├── Policies/
│   └── PostPolicy.php
├── Services/
│   ├── Square/
│   │   ├── PostCreationService.php
│   │   ├── PostFeedService.php
│   │   ├── PostManagementService.php
│   │   └── HashtagExtractionService.php
│   └── Media/
│       └── MediaUploadService.php
routes/
├── api.php
└── api/
    └── v1/
        ├── square.php
        ├── media.php
        └── search.php
```

---

## 3. Schéma Relationnel

```mermaid
erDiagram
    users ||--o{ posts : "authors"
    users ||--o{ post_tags : "is tagged"
    posts ||--|{ post_media : "contains (1..10)"
    post_media ||--o{ post_tags : "has tags"
    posts ||--o{ post_hashtag : "references"
    hashtags ||--o{ post_hashtag : "is referenced by"

    posts {
        bigint id PK
        bigint user_id FK
        bigint page_id FK "nullable"
        text body "nullable"
        string audience "public, followers, neighbourhood"
        string comments_by "everyone, followers, nobody"
        boolean comments_closed
        boolean allow_share
        boolean hide_likes
        boolean is_edited
        boolean is_archived
        string place_label "nullable"
        string place_detail "nullable"
        string place_city "nullable"
        string place_neighbourhood "nullable"
        string idempotency_key "nullable, unique"
        integer likes_count
        integer comments_count
        integer shares_count
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at "soft deletes"
    }

    post_media {
        bigint id PK
        bigint post_id FK
        string media_url
        string media_type "photo, video"
        string mime_type "nullable"
        bigint file_size "nullable"
        integer width "nullable"
        integer height "nullable"
        integer duration "nullable"
        integer display_order "0..9"
        jsonb edit_metadata "nullable"
        timestamp created_at
        timestamp updated_at
    }

    post_tags {
        bigint id PK
        bigint post_media_id FK
        bigint user_id FK
        decimal x "0.0 .. 1.0"
        decimal y "0.0 .. 1.0"
        timestamp created_at
        timestamp updated_at
    }

    hashtags {
        bigint id PK
        string name "unique"
        string normalized_name "unique"
        integer posts_count
        timestamp created_at
        timestamp updated_at
    }

    post_hashtag {
        bigint post_id FK
        bigint hashtag_id FK
    }
```
