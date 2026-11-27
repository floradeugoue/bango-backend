<?php

use App\Enums\Reel\ReelStatus;
use App\Models\Auth\Role;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\Support\WaitlistEntry;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->user = User::factory()->create([
        'name' => 'Marie',
        'allow_downloads' => true,
        'terms_accepted_at' => now(),
    ]);
});

test('privacy: allow_downloads est disponible en consultation et mise a jour', function (): void {
    Passport::actingAs($this->user);

    $getRes = $this->getJson('/api/profile/privacy');
    $getRes->assertOk();
    $getRes->assertJsonPath('allow_downloads', true);

    $putRes = $this->putJson('/api/profile/privacy', [
        'allow_downloads' => false,
    ]);
    $putRes->assertOk();
    $putRes->assertJsonPath('allow_downloads', false);

    $this->user->refresh();
    expect($this->user->allow_downloads)->toBeFalse();
});

test('branded video: retourne 200 quand pret, 202 quand en cours et 404 si telechargement interdit', function (): void {
    Passport::actingAs($this->user);

    $creator = User::factory()->create([
        'allow_downloads' => true,
    ]);

    // Cas 1 : reel pret avec URL
    $readyReel = Reel::factory()->create([
        'user_id' => $creator->id,
        'status' => ReelStatus::Ready->value,
        'allow_download' => true,
        'branded_video_url' => 'https://storage.bangoapp.com/reels/branded/123.mp4',
        'branded_status' => 'completed',
    ]);

    $resReady = $this->getJson("/api/reels/{$readyReel->id}/branded");
    $resReady->assertOk()
        ->assertJson([
            'url' => 'https://storage.bangoapp.com/reels/branded/123.mp4',
            'ready' => true,
        ]);

    // Cas 2 : en cours de fabrication
    $pendingReel = Reel::factory()->create([
        'user_id' => $creator->id,
        'status' => ReelStatus::Ready->value,
        'allow_download' => true,
        'branded_video_url' => null,
        'branded_status' => 'pending',
    ]);

    $resPending = $this->getJson("/api/reels/{$pendingReel->id}/branded");
    $resPending->assertStatus(202)
        ->assertJson([
            'ready' => false,
        ]);

    // Cas 3 : interdit sur le reel specifique
    $blockedReel = Reel::factory()->create([
        'user_id' => $creator->id,
        'status' => ReelStatus::Ready->value,
        'allow_download' => false,
    ]);

    $resBlocked = $this->getJson("/api/reels/{$blockedReel->id}/branded");
    $resBlocked->assertNotFound();

    // Cas 4 : interdit par les reglages globaux du createur
    $restrictedCreator = User::factory()->create([
        'allow_downloads' => false,
    ]);

    $creatorBlockedReel = Reel::factory()->create([
        'user_id' => $restrictedCreator->id,
        'status' => ReelStatus::Ready->value,
        'allow_download' => true,
    ]);

    $resCreatorBlocked = $this->getJson("/api/reels/{$creatorBlockedReel->id}/branded");
    $resCreatorBlocked->assertNotFound();
});

test('waitlist: app authentifiee permet de sinscrire et lister les topics', function (): void {
    Passport::actingAs($this->user);

    $res = $this->postJson('/api/waitlist', ['topic' => 'immo']);
    $res->assertStatus(201)
        ->assertJsonPath('topic', 'immo');

    // Ré-inscription idempodente
    $resAgain = $this->postJson('/api/waitlist', ['topic' => 'immo']);
    $resAgain->assertStatus(200)
        ->assertJsonPath('already_registered', true);

    $listRes = $this->getJson('/api/waitlist');
    $listRes->assertOk();
    expect($listRes->json('topics'))->toContain('immo');
});

test('waitlist: route publique bangoapp.com valide contact, consentement et repond avec CORS', function (): void {
    // Cas invalide : pas de consentement
    $resNoConsent = $this->postJson('/api/waitlist/public', [
        'contact' => 'awa@exemple.com',
        'topic' => 'immo',
        'consent' => false,
    ]);
    $resNoConsent->assertStatus(422);

    // Cas invalide : contact non conforme
    $resInvalidContact = $this->postJson('/api/waitlist/public', [
        'contact' => 'ceci_nest_pas_un_contact',
        'topic' => 'business',
        'consent' => true,
    ]);
    $resInvalidContact->assertStatus(422);

    // Inscription email valide avec origine bangoapp.com
    $resSuccess = $this->withHeaders(['Origin' => 'https://bangoapp.com'])
        ->postJson('/api/waitlist/public', [
            'contact' => 'awa@exemple.com',
            'topic' => 'immo',
            'locale' => 'fr',
            'consent' => true,
        ]);

    $resSuccess->assertStatus(201)
        ->assertJson(['ok' => true]);
    expect($resSuccess->headers->get('Access-Control-Allow-Origin'))->not->toBeNull();

    // Déjà inscrit -> 200
    $resRepeat = $this->withHeaders(['Origin' => 'https://bangoapp.com'])
        ->postJson('/api/waitlist/public', [
            'contact' => 'awa@exemple.com',
            'topic' => 'immo',
            'locale' => 'fr',
            'consent' => true,
        ]);

    $resRepeat->assertStatus(200)
        ->assertJson(['ok' => true]);

    // Inscription avec numéro de téléphone valide
    $resPhone = $this->postJson('/api/waitlist/public', [
        'contact' => '+237699000111',
        'topic' => 'app',
        'consent' => true,
    ]);
    $resPhone->assertStatus(201)
        ->assertJson(['ok' => true]);

    expect(WaitlistEntry::count())->toBe(2);
});

test('ai_generated: renvoye sur les publications', function (): void {
    Passport::actingAs($this->user);

    $res = $this->postJson('/api/square-cr1/posts', [
        'body' => 'Une publication creee avec un modele IA.',
        'ai_generated' => true,
    ]);

    $res->assertCreated();
    $res->assertJsonPath('data.ai_generated', true);
    $res->assertJsonPath('data.aiGenerated', true);

    $postId = $res->json('data.id');
    $post = Post::find($postId);
    expect($post->ai_generated)->toBeTrue();
});

test('ai_generated: renvoye sur les stories et slides', function (): void {
    Storage::fake('public');
    Passport::actingAs($this->user);

    $fakeImage = UploadedFile::fake()->image('story_ia.jpg', 1080, 1920);

    $res = $this->postJson('/api/stories', [
        'file' => $fakeImage,
        'ai_generated' => true,
        'caption' => 'Story IA',
    ]);

    $res->assertCreated();
    $res->assertJsonPath('data.ai_generated', true);
    $res->assertJsonPath('data.aiGenerated', true);
});

test('waitlist admin: lister le nombre dinscrits par sujet et exporter en CSV', function (): void {
    $this->seed(RoleSeeder::class);

    WaitlistEntry::create([
        'contact' => 'client1@bangoapp.com',
        'topic' => 'immo',
        'source' => 'web',
        'locale' => 'fr',
        'consent' => true,
    ]);
    WaitlistEntry::create([
        'contact' => 'client2@bangoapp.com',
        'topic' => 'business',
        'source' => 'web',
        'locale' => 'fr',
        'consent' => true,
    ]);

    $role = Role::where('slug', 'admin')->firstOrFail();
    $admin = User::factory()->create([
        'name' => 'Admin Test',
        'role_id' => $role->id,
        'status' => 'active',
    ]);

    Passport::actingAs($admin);

    // Consultation sans filtre
    $indexRes = $this->getJson('/api/admin/waitlist');
    $indexRes->assertOk();
    $indexRes->assertJsonPath('success', true);
    $indexRes->assertJsonPath('summary.total', 2);
    expect($indexRes->json('summary.counts'))->toHaveKey('immo');

    // Export CSV
    $exportRes = $this->get('/api/admin/waitlist/export');
    $exportRes->assertOk();
    $exportRes->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($exportRes->streamedContent())->toContain('client1@bangoapp.com');
    expect($exportRes->streamedContent())->toContain('client2@bangoapp.com');
});
