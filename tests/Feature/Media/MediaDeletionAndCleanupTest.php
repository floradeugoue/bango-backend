<?php

namespace Tests\Feature\Media;

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Enums\Square\PostMediaKind;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use App\Services\Media\MediaStorageService;
use App\Services\Message\MessageService;
use App\Services\Square\PostManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MediaDeletionAndCleanupTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MediaStorageService $storageService;

    protected string $publicDisk;

    protected string $privateDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->publicDisk = config('media.disk', 'public');
        $this->privateDisk = config('media.private_disk', 'local');

        Storage::fake($this->publicDisk);
        Storage::fake($this->privateDisk);

        $this->user = User::factory()->create(['name' => 'Jean Dupont']);
        $this->storageService = app(MediaStorageService::class);
    }

    public function test_suppression_d_une_publication_avec_photo(): void
    {
        $photoPath = 'square/images/'.$this->user->id.'/test-photo.jpg';
        Storage::disk($this->publicDisk)->put($photoPath, 'PHOTO_BINARY_DATA');
        Storage::disk($this->publicDisk)->assertExists($photoPath);

        $post = Post::factory()->create([
            'user_id' => $this->user->id,
            'body' => 'Belle photo Square',
        ]);

        $postMedia = PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $photoPath,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        // Exécution de la suppression de la publication
        $service = app(PostManagementService::class);
        $deleted = $service->delete($post);

        expect($deleted)->toBeTrue();
        expect(Post::find($post->id))->toBeNull(); // Soft-deleté
        expect(PostMedia::find($postMedia->id))->toBeNull(); // Supprimé en DB

        // Le fichier photo physique DOIT avoir été supprimé du stockage
        Storage::disk($this->publicDisk)->assertMissing($photoPath);
    }

    public function test_suppression_d_une_publication_avec_video_et_poster(): void
    {
        $videoPath = 'square/videos/'.$this->user->id.'/test-video.mp4';
        $posterPath = 'square/videos/'.$this->user->id.'/test-poster.jpg';

        Storage::disk($this->publicDisk)->put($videoPath, 'VIDEO_BINARY_DATA');
        Storage::disk($this->publicDisk)->put($posterPath, 'POSTER_BINARY_DATA');

        Storage::disk($this->publicDisk)->assertExists($videoPath);
        Storage::disk($this->publicDisk)->assertExists($posterPath);

        $post = Post::factory()->create([
            'user_id' => $this->user->id,
            'body' => 'Superbe vidéo Square',
        ]);

        PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $videoPath,
            'poster_url' => $posterPath,
            'media_type' => PostMediaKind::Video,
            'mime_type' => 'video/mp4',
            'display_order' => 1,
        ]);

        $service = app(PostManagementService::class);
        $service->delete($post);

        // La vidéo source et son poster DOIVENT être supprimés
        Storage::disk($this->publicDisk)->assertMissing($videoPath);
        Storage::disk($this->publicDisk)->assertMissing($posterPath);
    }

    public function test_suppression_d_une_video_avec_plusieurs_variantes_ffmpeg(): void
    {
        $uniqueId = (string) Str::uuid();
        $renditionsDir = "square/renditions/{$this->user->id}/{$uniqueId}";

        $r480p = "{$renditionsDir}/480p.mp4";
        $r720p = "{$renditionsDir}/720p.mp4";
        $r1080p = "{$renditionsDir}/1080p.mp4";
        $sourceVideo = "square/videos/{$this->user->id}/original.mp4";

        Storage::disk($this->publicDisk)->put($sourceVideo, 'SOURCE_VIDEO');
        Storage::disk($this->publicDisk)->put($r480p, 'DATA_480');
        Storage::disk($this->publicDisk)->put($r720p, 'DATA_720');
        Storage::disk($this->publicDisk)->put($r1080p, 'DATA_1080');

        $post = Post::factory()->create(['user_id' => $this->user->id]);

        PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $sourceVideo,
            'media_type' => PostMediaKind::Video,
            'mime_type' => 'video/mp4',
            'display_order' => 1,
            'edit_metadata' => [
                'renditions' => [
                    '480p' => $r480p,
                    '720p' => $r720p,
                    '1080p' => $r1080p,
                ],
            ],
        ]);

        $service = app(PostManagementService::class);
        $service->delete($post);

        // Toutes les variantes et la vidéo source doivent être purgées
        Storage::disk($this->publicDisk)->assertMissing($sourceVideo);
        Storage::disk($this->publicDisk)->assertMissing($r480p);
        Storage::disk($this->publicDisk)->assertMissing($r720p);
        Storage::disk($this->publicDisk)->assertMissing($r1080p);
    }

    public function test_suppression_d_un_message_avec_piece_jointe(): void
    {
        $conversation = Conversation::create([
            'id' => (string) Str::uuid(),
            'type' => ConversationType::Direct,
            'status' => 'active',
            'created_by_id' => $this->user->id,
        ]);

        ConversationMember::create([
            'conversation_id' => $conversation->id,
            'user_id' => $this->user->id,
            'role' => ConversationMemberRole::Member,
        ]);

        $voicePath = 'messages/voices/'.$conversation->id.'/voice-note.m4a';
        Storage::disk($this->privateDisk)->put($voicePath, 'PRIVATE_VOICE_DATA');
        Storage::disk($this->privateDisk)->assertExists($voicePath);

        $message = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'type' => MessageType::Voice,
            'attachment_path' => $voicePath,
        ]);

        $messageService = app(MessageService::class);
        $messageService->retractMessage($conversation, $message, $this->user);

        // La pièce jointe physique privée DOIT être supprimée
        Storage::disk($this->privateDisk)->assertMissing($voicePath);
    }

    public function test_un_media_partage_nest_pas_supprime_prematurement(): void
    {
        // Un même fichier physique est partagé / réutilisé par deux publications
        $sharedPhoto = 'square/images/'.$this->user->id.'/shared-graphic.jpg';
        Storage::disk($this->publicDisk)->put($sharedPhoto, 'SHARED_GRAPHIC_DATA');

        $post1 = Post::factory()->create(['user_id' => $this->user->id, 'body' => 'Post 1 Original']);
        $postMedia1 = PostMedia::create([
            'post_id' => $post1->id,
            'media_url' => $sharedPhoto,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        $user2 = User::factory()->create(['name' => 'Bob']);
        $post2 = Post::factory()->create(['user_id' => $user2->id, 'body' => 'Post 2 Republication']);
        PostMedia::create([
            'post_id' => $post2->id,
            'media_url' => $sharedPhoto,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        $service = app(PostManagementService::class);

        // 1. Suppression du Post 1 uniquement
        $service->delete($post1);

        // Le fichier DOIT TOUJOURS EXISTER sur le disque car Post 2 l'utilise encore !
        Storage::disk($this->publicDisk)->assertExists($sharedPhoto);

        // 2. Suppression du Post 2
        $service->delete($post2);

        // Maintenant que plus aucun post ne le référence, le fichier DOIT être supprimé
        Storage::disk($this->publicDisk)->assertMissing($sharedPhoto);
    }

    public function test_gestion_des_erreurs_de_stockage_sans_incoherence_db(): void
    {
        // Si le fichier n'existe déjà plus sur le disque (erreur de stockage), la suppression DB ne doit pas échouer
        $ghostPath = 'square/images/'.$this->user->id.'/ghost-file.jpg';
        Storage::disk($this->publicDisk)->assertMissing($ghostPath);

        $post = Post::factory()->create(['user_id' => $this->user->id]);
        PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $ghostPath,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        $service = app(PostManagementService::class);

        // Ne doit pas lancer d'exception fatale
        $result = $service->delete($post);
        expect($result)->toBeTrue();
        expect(Post::find($post->id))->toBeNull();
    }

    public function test_nettoyage_des_fichiers_orphelins_mode_simulation_et_force(): void
    {
        $orphanOld = 'square/images/'.$this->user->id.'/old-orphan.jpg';
        $orphanRecent = 'square/images/'.$this->user->id.'/recent-upload.jpg';

        Storage::disk($this->publicDisk)->put($orphanOld, 'OLD_ORPHAN');
        Storage::disk($this->publicDisk)->put($orphanRecent, 'RECENT_UPLOAD');

        // Simuler un vieux fichier (âge > 48h) en modifiant mtime
        $diskPath = Storage::disk($this->publicDisk)->path($orphanOld);
        if (file_exists($diskPath)) {
            touch($diskPath, time() - (48 * 3600));
        }

        // 1. Exécution en mode simulation (--dry-run)
        Artisan::call('media:clean-orphans', [
            '--disk' => $this->publicDisk,
            '--hours' => 24,
            '--dry-run' => true,
        ]);

        // Rien ne doit être supprimé en mode simulation
        Storage::disk($this->publicDisk)->assertExists($orphanOld);
        Storage::disk($this->publicDisk)->assertExists($orphanRecent);

        // 2. Exécution réelle avec --force
        Artisan::call('media:clean-orphans', [
            '--disk' => $this->publicDisk,
            '--hours' => 24,
            '--force' => true,
        ]);

        // Le vieux fichier orphelin (>24h) a été purgé
        Storage::disk($this->publicDisk)->assertMissing($orphanOld);

        // Le fichier récent (<24h) EST PROTÉGÉ (sécurité anti-suppression en cours d'upload)
        Storage::disk($this->publicDisk)->assertExists($orphanRecent);
    }
}
