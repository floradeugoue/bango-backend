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
use App\Services\Media\VideoTranscodingService;
use App\Services\Message\VoiceMessageService;
use App\Services\Square\PostManagementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class CloudflareR2PreparationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MediaStorageService $mediaService;

    protected function setUp(): void
    {
        parent::setUp();

        // Configuration pour simuler l'environnement Cloudflare R2
        Config::set('filesystems.disks.r2.url', 'https://cdn.bango.app');
        Config::set('media.disk', 'r2');
        Config::set('media.private_disk', 'r2_private');

        Storage::fake('r2', ['url' => 'https://cdn.bango.app']);
        Storage::fake('r2_private');
        Storage::fake('public');

        // Nettoyer les résidus éventuels de tests précédents dans storage/app/temp
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            foreach (glob("{$tempDir}/transcode_*") ?: [] as $dir) {
                if (is_dir($dir)) {
                    foreach (glob("{$dir}/*") ?: [] as $f) {
                        @unlink($f);
                    }
                    @rmdir($dir);
                }
            }
        }

        $this->user = User::factory()->create(['name' => 'Alice R2']);
        $this->mediaService = app(MediaStorageService::class);
    }

    public function test_upload_d_une_photo_sur_r2(): void
    {
        $file = UploadedFile::fake()->image('nature.jpg', 800, 600);

        $result = $this->mediaService->storeSquareMedia($file, $this->user, 'r2');

        expect($result['disk'])->toBe('r2');
        expect($result['type'])->toBe('photo');
        Storage::disk('r2')->assertExists($result['path']);

        // Vérification que l'URL publique générée utilise le domaine CDN configuré
        expect($result['url'])->toContain('https://cdn.bango.app');
    }

    public function test_upload_d_une_video_sur_r2(): void
    {
        $videoFile = UploadedFile::fake()->create('dance.mp4', 2048, 'video/mp4');

        $result = $this->mediaService->storeSquareMedia($videoFile, $this->user, 'r2');

        expect($result['disk'])->toBe('r2');
        expect($result['type'])->toBe('video');
        Storage::disk('r2')->assertExists($result['path']);
        expect($result['url'])->toContain('https://cdn.bango.app');
    }

    public function test_upload_d_une_piece_jointe_vocale_sur_r2_prive(): void
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

        $audioFile = UploadedFile::fake()->create('voicenote.m4a', 300, 'audio/x-m4a');
        $voiceService = app(VoiceMessageService::class);

        $message = $voiceService->storeVoiceNote(
            conversation: $conversation,
            sender: $this->user,
            audioFile: $audioFile,
            options: ['duration_ms' => 4500]
        );

        expect($message->type)->toBe(MessageType::Voice);
        expect($message->attachment_path)->not->toBeNull();

        // Le fichier privé DOIT être stocké sur r2_private et NON sur le disque public
        Storage::disk('r2_private')->assertExists($message->attachment_path);
        Storage::disk('r2')->assertMissing($message->attachment_path);

        // L'URL du message est une route signée sécurisée
        expect($message->attachment_url)->toContain('/api/messages/'.$message->id.'/attachment');
        expect($message->attachment_url)->not->toContain('https://cdn.bango.app');
    }

    public function test_lecture_d_un_media_public_via_cdn(): void
    {
        $path = 'square/images/'.$this->user->id.'/public-pic.jpg';
        Storage::disk('r2')->put($path, 'IMAGE_DATA');

        $post = Post::factory()->create(['user_id' => $this->user->id]);
        $postMedia = PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $path,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        expect($postMedia->media_url)->toContain('https://cdn.bango.app');
        expect($postMedia->media_url)->toContain($path);
    }

    public function test_acces_autorise_a_un_media_prive_sur_r2(): void
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

        $voicePath = 'messages/voices/'.$conversation->id.'/note.m4a';
        Storage::disk('r2_private')->put($voicePath, 'SECRET_AUDIO_STREAM');

        $message = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $conversation->id,
            'sender_id' => $this->user->id,
            'type' => MessageType::Voice,
            'attachment_path' => $voicePath,
            'attachment_mime' => 'audio/x-m4a',
        ]);

        Passport::actingAs($this->user);

        // Appel à la route sécurisée : le backend stream le fichier depuis r2_private
        $response = $this->get("/api/messages/{$message->id}/attachment");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/x-m4a');
        expect($response->streamedContent())->toBe('SECRET_AUDIO_STREAM');
    }

    public function test_suppression_d_un_media_sur_r2(): void
    {
        $photoPath = 'square/images/'.$this->user->id.'/to-delete.jpg';
        Storage::disk('r2')->put($photoPath, 'DELETE_ME');
        Storage::disk('r2')->assertExists($photoPath);

        $post = Post::factory()->create(['user_id' => $this->user->id]);
        PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $photoPath,
            'media_type' => PostMediaKind::Photo,
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        $postService = app(PostManagementService::class);
        $postService->delete($post);

        // Le fichier doit être supprimé du disque R2
        Storage::disk('r2')->assertMissing($photoPath);
    }

    public function test_upload_des_resultats_ffmpeg_vers_r2_et_nettoyage_temporaire(): void
    {
        $sourcePath = 'square/videos/'.$this->user->id.'/original-test.mp4';
        Storage::disk('r2')->put($sourcePath, 'ORIGINAL_VIDEO_CONTENT');

        /** @var VideoTranscodingService $transcoder */
        $transcoder = app(VideoTranscodingService::class);
        $result = $transcoder->transcode($sourcePath, 'square/renditions', $this->user->id, 'r2');

        // Vérification des renditions sur R2
        expect($result['renditions'])->toHaveKeys(['480p', '720p', '1080p']);
        foreach ($result['relative_renditions'] as $renditionPath) {
            Storage::disk('r2')->assertExists($renditionPath);
        }

        // Vérification du poster sur R2
        Storage::disk('r2')->assertExists($result['poster_path']);

        // Vérification qu'aucun fichier temporaire de transcodage n'est resté orphelin sur le disque local
        $tempDir = storage_path('app/temp');
        if (is_dir($tempDir)) {
            $leftoverTranscodeDirs = glob("{$tempDir}/transcode_*");
            expect($leftoverTranscodeDirs)->toBeEmpty();
        }
    }

    public function test_migration_vers_r2_avec_reprise_apres_interruption_et_integrite(): void
    {
        // 1. Création de deux fichiers sur le stockage local 'public'
        $file1 = 'square/images/1/file1.jpg';
        $file2 = 'square/images/1/file2.jpg';

        Storage::disk('public')->put($file1, 'CONTENT_FILE_1');
        Storage::disk('public')->put($file2, 'CONTENT_FILE_2');

        // Simuler qu'un des fichiers est déjà transféré sur R2 avec intégrité validée
        Storage::disk('r2')->put($file1, 'CONTENT_FILE_1');

        // Exécution de la commande de migration
        Artisan::call('media:migrate-to-r2', [
            '--type' => 'public',
            '--source-disk' => 'public',
            '--target-disk' => 'r2',
            '--delete-source' => true,
        ]);

        // Vérification : file2 a été transféré vers R2
        Storage::disk('r2')->assertExists($file2);
        expect(Storage::disk('r2')->get($file2))->toBe('CONTENT_FILE_2');

        // Les deux fichiers locaux sources ont été nettoyés après vérification stricte d'intégrité
        Storage::disk('public')->assertMissing($file1);
        Storage::disk('public')->assertMissing($file2);
    }

    public function test_gestion_d_une_erreur_de_connexion_r2(): void
    {
        // Tente de migrer vers un disque inexistant ou invalide
        $exitCode = Artisan::call('media:migrate-to-r2', [
            '--type' => 'public',
            '--source-disk' => 'public',
            '--target-disk' => 'disk_invalide_non_configure',
        ]);

        // La commande doit échouer avec code d'erreur (1) sans crash fatal
        expect($exitCode)->toBe(1);
    }
}
