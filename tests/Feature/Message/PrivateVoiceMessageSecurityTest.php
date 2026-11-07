<?php

namespace Tests\Feature\Message;

use App\Enums\Message\ConversationMemberRole;
use App\Enums\Message\ConversationType;
use App\Enums\Message\MessageType;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Message\Message;
use App\Models\Square\Post;
use App\Models\Square\PostMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Tests\TestCase;

class PrivateVoiceMessageSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $alice;

    protected User $bob;

    protected User $charlie; // Utilisateur extérieur / intrus

    protected Conversation $directThread;

    protected Conversation $groupThread;

    protected Message $voiceMessage;

    protected string $privateDisk;

    protected string $publicDisk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateDisk = config('media.private_disk', 'local');
        $this->publicDisk = config('media.disk', 'public');

        Storage::fake($this->privateDisk);
        Storage::fake($this->publicDisk);

        $this->alice = User::factory()->create(['name' => 'Alice']);
        $this->bob = User::factory()->create(['name' => 'Bob']);
        $this->charlie = User::factory()->create(['name' => 'Charlie Intrus']);

        // 1. Conversation directe entre Alice et Bob
        $this->directThread = Conversation::create([
            'id' => (string) Str::uuid(),
            'type' => ConversationType::Direct,
            'status' => 'active',
            'created_by_id' => $this->alice->id,
        ]);

        ConversationMember::create([
            'conversation_id' => $this->directThread->id,
            'user_id' => $this->alice->id,
            'role' => ConversationMemberRole::Member,
        ]);
        ConversationMember::create([
            'conversation_id' => $this->directThread->id,
            'user_id' => $this->bob->id,
            'role' => ConversationMemberRole::Member,
        ]);

        // 2. Fichier audio de note vocale stocké sur le disque privé
        $voicePath = 'messages/voices/'.$this->directThread->id.'/test-voice.m4a';
        Storage::disk($this->privateDisk)->put($voicePath, 'FAKE_AUDIO_BINARY_CONTENT');

        $this->voiceMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->directThread->id,
            'sender_id' => $this->alice->id,
            'type' => MessageType::Voice,
            'attachment_path' => $voicePath,
            'attachment_mime' => 'audio/x-m4a',
            'attachment_size' => 1024,
            'attachment_duration_ms' => 3500,
        ]);

        // 3. Conversation de groupe (Alice, Bob) sans Charlie
        $this->groupThread = Conversation::create([
            'id' => (string) Str::uuid(),
            'type' => ConversationType::Group,
            'title' => 'Groupe Secret',
            'status' => 'active',
            'created_by_id' => $this->alice->id,
        ]);
        ConversationMember::create([
            'conversation_id' => $this->groupThread->id,
            'user_id' => $this->alice->id,
            'role' => ConversationMemberRole::Admin,
        ]);
        ConversationMember::create([
            'conversation_id' => $this->groupThread->id,
            'user_id' => $this->bob->id,
            'role' => ConversationMemberRole::Member,
        ]);
    }

    public function test_un_utilisateur_autorise_peut_acceder_au_fichier_vocal(): void
    {
        // Alice est l'expéditrice et membre de la conversation
        Passport::actingAs($this->alice);

        $response = $this->get("/api/messages/{$this->voiceMessage->id}/attachment");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'audio/x-m4a');
        $response->assertHeader('Cache-Control', 'must-revalidate, no-cache, no-store, private');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        expect($response->streamedContent())->toBe('FAKE_AUDIO_BINARY_CONTENT');
    }

    public function test_le_destinataire_membre_peut_aussi_acceder_au_fichier_vocal(): void
    {
        // Bob est le destinataire et membre de la conversation
        Passport::actingAs($this->bob);

        $response = $this->get("/api/messages/{$this->voiceMessage->id}/attachment");

        $response->assertStatus(200);
        expect($response->streamedContent())->toBe('FAKE_AUDIO_BINARY_CONTENT');
    }

    public function test_un_utilisateur_non_connecte_est_rejete_sans_signature(): void
    {
        // Aucun utilisateur connecté, pas de signature
        $response = $this->getJson("/api/messages/{$this->voiceMessage->id}/attachment");

        $response->assertStatus(401);
    }

    public function test_un_utilisateur_exterieur_a_la_conversation_est_bloque_avec_403(): void
    {
        // Charlie est connecté mais ne fait pas partie de la conversation
        Passport::actingAs($this->charlie);

        $response = $this->getJson("/api/messages/{$this->voiceMessage->id}/attachment");

        $response->assertStatus(403);
    }

    public function test_un_utilisateur_ne_peut_pas_deviner_lurl_et_acceder_au_fichier(): void
    {
        // Charlie tente d'accéder avec un faux paramètre de signature ou en devinant l'identifiant
        Passport::actingAs($this->charlie);

        $forgedUrl = "/api/messages/{$this->voiceMessage->id}/attachment?signature=invalid_hmac_guess";
        $response = $this->getJson($forgedUrl);

        $response->assertStatus(403);
    }

    public function test_une_url_signee_temporaire_est_generee_dans_le_modele(): void
    {
        $signedUrl = $this->voiceMessage->attachment_url;

        expect($signedUrl)->not->toBeNull();
        expect($signedUrl)->toContain('/api/messages/'.$this->voiceMessage->id.'/attachment');
        expect($signedUrl)->toContain('signature=');
        expect($signedUrl)->not->toContain('/storage/messages/voices/');

        // Un lecteur audio mobile peut streamer via cette URL signée temporaire
        $response = $this->get($signedUrl);
        $response->assertStatus(200);
        expect($response->streamedContent())->toBe('FAKE_AUDIO_BINARY_CONTENT');
    }

    public function test_autorisations_conversations_de_groupe_pour_les_notes_vocales(): void
    {
        $groupVoicePath = 'messages/voices/'.$this->groupThread->id.'/group-voice.m4a';
        Storage::disk($this->privateDisk)->put($groupVoicePath, 'GROUP_AUDIO_CONTENT');

        $groupMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->groupThread->id,
            'sender_id' => $this->alice->id,
            'type' => MessageType::Voice,
            'attachment_path' => $groupVoicePath,
            'attachment_mime' => 'audio/x-m4a',
            'attachment_size' => 2048,
        ]);

        // 1. Membre du groupe autorisé
        Passport::actingAs($this->bob);
        $resBob = $this->get("/api/messages/{$groupMessage->id}/attachment");
        $resBob->assertStatus(200);
        expect($resBob->streamedContent())->toBe('GROUP_AUDIO_CONTENT');

        // 2. Non-membre du groupe refusé
        Passport::actingAs($this->charlie);
        $resCharlie = $this->getJson("/api/messages/{$groupMessage->id}/attachment");
        $resCharlie->assertStatus(403);
    }

    public function test_le_fichier_prive_nest_pas_accessible_par_l_ancienne_url_publique(): void
    {
        // Vérifie que le fichier N'EST PAS sur le disque public
        Storage::disk($this->publicDisk)->assertMissing($this->voiceMessage->attachment_path);

        // L'URL publique /storage/... pointe sur public storage et renverrait un 404 du serveur HTTP
        expect(Storage::disk($this->publicDisk)->exists($this->voiceMessage->attachment_path))->toBeFalse();
    }

    public function test_un_message_retracte_ne_permet_plus_d_acceder_au_fichier(): void
    {
        $this->voiceMessage->update([
            'is_retracted' => true,
            'attachment_path' => null,
        ]);

        Passport::actingAs($this->alice);

        $response = $this->getJson("/api/messages/{$this->voiceMessage->id}/attachment");
        $response->assertStatus(404);
    }

    public function test_les_medias_publics_du_square_restent_accessibles_selon_les_regles_existantes(): void
    {
        // Un média public du Square est stocké sur le disque public
        $squarePath = 'square/images/'.$this->alice->id.'/public-photo.jpg';
        Storage::disk($this->publicDisk)->put($squarePath, 'SQUARE_IMAGE_DATA');

        $post = Post::factory()->create([
            'user_id' => $this->alice->id,
            'body' => 'Publication publique Square',
        ]);

        $postMedia = PostMedia::create([
            'post_id' => $post->id,
            'media_url' => $squarePath,
            'media_type' => 'photo',
            'mime_type' => 'image/jpeg',
            'display_order' => 1,
        ]);

        // Le média public existe bien sur le disque public
        Storage::disk($this->publicDisk)->assertExists($squarePath);
        expect($postMedia->media_url)->toBe('/storage/'.$squarePath);
    }

    public function test_la_commande_de_migration_deplace_les_anciens_fichiers_publics_vers_le_prive(): void
    {
        // Simule un ancien message vocal enregistré sur le disque public (ancienne faille)
        $oldVoicePath = 'messages/voices/'.$this->directThread->id.'/old-legacy-voice.m4a';
        Storage::disk($this->publicDisk)->put($oldVoicePath, 'OLD_LEGACY_AUDIO');
        Storage::disk($this->privateDisk)->assertMissing($oldVoicePath);

        $legacyMessage = Message::create([
            'id' => (string) Str::uuid(),
            'conversation_id' => $this->directThread->id,
            'sender_id' => $this->alice->id,
            'type' => MessageType::Voice,
            'attachment_path' => $oldVoicePath,
            'attachment_mime' => 'audio/x-m4a',
        ]);

        // Exécution de la commande Artisan de migration
        Artisan::call('media:migrate-private-voices');

        // Vérification : le fichier a été transféré sur le disque privé
        Storage::disk($this->privateDisk)->assertExists($oldVoicePath);
        expect(Storage::disk($this->privateDisk)->get($oldVoicePath))->toBe('OLD_LEGACY_AUDIO');

        // Et supprimé du disque public pour clore la brèche
        Storage::disk($this->publicDisk)->assertMissing($oldVoicePath);

        // L'utilisateur autorisé peut désormais y accéder via la route sécurisée
        Passport::actingAs($this->alice);
        $res = $this->get("/api/messages/{$legacyMessage->id}/attachment");
        $res->assertStatus(200);
        expect($res->streamedContent())->toBe('OLD_LEGACY_AUDIO');
    }
}
