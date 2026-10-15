<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Disque de stockage actif pour les médias
    |--------------------------------------------------------------------------
    | En développement local : 'public' (stocké dans storage/app/public,
    | accessible via /storage après php artisan storage:link).
    | En production : 'r2' (Cloudflare R2) ou 's3' (AWS S3).
    */
    'disk' => env('MEDIA_DISK', 'public'),

    /*
    |--------------------------------------------------------------------------
    | Fournisseur de diffusion vidéo en streaming
    |--------------------------------------------------------------------------
    | 'cloudflare_stream' pour l'encodage et la diffusion HLS adaptative.
    | 'local' pour la diffusion directe du fichier vidéo local.
    */
    'stream_provider' => env('STREAM_PROVIDER', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Durée de validité des URLs temporaires / pré-signées (en minutes)
    |--------------------------------------------------------------------------
    */
    'temporary_url_ttl' => (int) env('MEDIA_TEMPORARY_URL_TTL', 60),

    /*
    |--------------------------------------------------------------------------
    | Dossiers d'organisation du stockage
    |--------------------------------------------------------------------------
    */
    'paths' => [
        // Reels & Studio Reel (CR2, RL)
        'reels_videos' => 'reels/videos/{user_id}',
        'reels_covers' => 'reels/covers/{user_id}',
        'reels_thumbnails' => 'reels/thumbnails/{user_id}',
        'reels_drafts' => 'reels/drafts/{user_id}',
        'reels_temp' => 'reels/temp',

        // Sons & Musique (RL)
        'sounds_catalogue' => 'sounds/catalogue',
        'sounds_originals' => 'sounds/originals/{user_id}',
        'sounds_temp' => 'sounds/temp',

        // Square (CR1)
        'square_images' => 'square/images/{user_id}',
        'square_videos' => 'square/videos/{user_id}',
        'square_temp' => 'square/temp',

        // Profil & Avatars
        'avatars' => 'avatars',

        // Compatibilité arrière (anciens alias)
        'reels_clips' => 'reels/videos/{user_id}',
        'sounds' => 'sounds/catalogue',
        'original_sounds' => 'sounds/originals/{user_id}',
        'temp' => 'reels/temp',
    ],

    /*
    |--------------------------------------------------------------------------
    | Limites de taille (en octets)
    |--------------------------------------------------------------------------
    */
    'max_video_size' => 100 * 1024 * 1024, // 100 Mo
    'max_image_size' => 15 * 1024 * 1024,  // 15 Mo
    'max_audio_size' => 30 * 1024 * 1024,  // 30 Mo
];
