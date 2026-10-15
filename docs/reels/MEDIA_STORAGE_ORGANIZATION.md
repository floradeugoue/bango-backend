# BANGO — Organisation et Séparation des Médias dans `storage/app/public`

Ce document décrit l'organisation physique et la centralisation des médias stockés dans le backend BANGO.

---

## 1. Arborescence Cible des Dossiers

Tous les médias de l'application sont clairement cloisonnés par module et par typologie de contenu afin d'éviter tout mélange ou collision de nommage :

```text
storage/app/public/
├── avatars/                       <-- Photos de profil des utilisateurs
├── reels/                         <-- Module Studio Reel & Reels (CR2, RL)
│   ├── videos/                    <-- Vidéos finalisées hébergées en local
│   ├── covers/                    <-- Images de couverture sélectionnées
│   ├── thumbnails/                <-- Miniatures vidéo générées
│   ├── drafts/                    <-- Clips de brouillons Studio Reel
│   └── temp/                      <-- Fichiers temporaires d'encodage
├── sounds/                        <-- Module Sons & Musique (RL)
│   ├── catalogue/                 <-- Fichiers audio officiels du catalogue
│   ├── originals/                 <-- Sons originaux créés par les utilisateurs
│   └── temp/                      <-- Enregistrements audio temporaires
└── square/                        <-- Module Publications Square (CR1)
    ├── images/                    <-- Photos publiées dans le carrousel Square
    ├── videos/                    <-- Vidéos publiées dans le feed Square
    └── temp/                      <-- Média temporaire de pré-publication
```

Chaque sous-dossier contient un fichier `.gitignore` conservant la structure vide dans Git tout en excluant les fichiers médias volumineux du versionnement.

---

## 2. Centralisation de la Gestion des Chemins

Tous les chemins sont centralisés dans `config/media.php` et consommés via `MediaStorageService` :

```php
// config/media.php
'paths' => [
    'reels_videos' => 'reels/videos/{user_id}',
    'reels_covers' => 'reels/covers/{user_id}',
    'reels_thumbnails' => 'reels/thumbnails/{user_id}',
    'reels_drafts' => 'reels/drafts/{user_id}',
    'reels_temp' => 'reels/temp',

    'sounds_catalogue' => 'sounds/catalogue',
    'sounds_originals' => 'sounds/originals/{user_id}',
    'sounds_temp' => 'sounds/temp',

    'square_images' => 'square/images/{user_id}',
    'square_videos' => 'square/videos/{user_id}',
    'square_temp' => 'square/temp',

    'avatars' => 'avatars',
];
```

### Méthodes disponibles dans `MediaStorageService` :
* `pathFor(string $type, array $params = []): string` : Résout le chemin dynamique.
* `storeReelVideo($file, $user)` : Enregistre dans `reels/videos/{user_id}/`.
* `storeReelCover($file, $user)` : Enregistre dans `reels/covers/{user_id}/`.
* `storeReelThumbnail($file, $user)` : Enregistre dans `reels/thumbnails/{user_id}/`.
* `storeReelDraftMedia($file, $user)` : Enregistre dans `reels/drafts/{user_id}/`.
* `storeAudio($file, $user, $isOriginal)` : Enregistre dans `sounds/catalogue/` ou `sounds/originals/{user_id}/`.
* `storeSquareMedia($file, $user)` : Sépare automatiquement dans `square/images/{user_id}/` ou `square/videos/{user_id}/`.
* `storeTempMedia($file, $module)` : Enregistre dans le sous-dossier `temp` du module.
* `getUrl($path)` : Résout l'URL publique de manière transparente (local ou distant).
* `delete($pathOrUrl)` : Supprime le fichier physique en préservant les URLs externes (Cloudflare Stream).

---

## 3. Compatibilité Arrière

* **Anciens chemins :** Les références en base de données ou les tests utilisant des chemins antérieurs (`reels/{user_id}/clips/...`, `sounds/...`, `square/{user_id}/...`) continuent d'être lus, vérifiés (`exists`) et supprimés (`delete`) sans régression grâce à `MediaStorageService::extractRelativePath()`.
* **Flux externes :** Les vidéos diffusées par Cloudflare Stream (`https://customer-xxx.cloudflarestream.com/...`) ne sont pas traitées comme des fichiers locaux et ne subissent aucune tentative de suppression sur le disque local.

---

## 4. Outil d'Audit et Détection d'Orphelins

Une commande Artisan en lecture seule est disponible pour superviser le stockage :

```bash
php artisan media:audit
```

Options :
* `--disk=public` : Permet d'auditer un disque spécifique.
* `--ensure-dirs` : Crée automatiquement les répertoires manquants s'ils n'existent pas encore.

Elle présente un tableau récapitulatif par sous-dossier et identifie les fichiers orphelins (fichiers présents sur disque mais non référencés dans les tables `reels`, `sounds`, `post_media` ou `users`), **sans aucune suppression destructive**.
