<?php

use App\Enums\Boost\CampaignObjective;
use App\Enums\Message\ConversationType;
use App\Enums\Page\PageCertificationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageStatus;
use App\Models\Auth\Role;
use App\Models\Boost\CreditWallet;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\Reel\Reel;
use App\Models\Square\Post;
use App\Models\User;
use Database\Seeders\SubscriptionCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    $this->seed(SubscriptionCatalogSeeder::class);

    $this->adminRole = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Administrateur']);

    $this->owner = User::factory()->create(['name' => 'Alice Fondatrice']);
    $this->successor = User::factory()->create(['name' => 'Bob Repreneur']);
    $this->editor = User::factory()->create(['name' => 'Charlie Membre']);
    $this->admin = User::factory()->create(['name' => 'Admin BANGO', 'role_id' => $this->adminRole->id]);

    $this->page = Page::factory()->create([
        'owner_id' => $this->owner->id,
        'name' => 'Complexe Commercial Bonanjo',
        'status' => PageStatus::Active,
        'certification_status' => PageCertificationStatus::Approved,
    ]);

    $this->ownerMember = PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->owner->id,
        'role' => PageRole::Owner,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $this->editorMember = PageMember::create([
        'page_id' => $this->page->id,
        'user_id' => $this->editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted,
        'accepted_at' => now(),
    ]);
});

test('EDGE-01 : il est impossible de révoquer le dernier propriétaire de la page', function () {
    Passport::actingAs($this->owner);

    $response = $this->deleteJson("/api/pages/{$this->page->id}/members/{$this->ownerMember->id}");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['member']);

    expect($this->ownerMember->fresh()->status)->toBe(PageMemberStatus::Accepted);
});

test('EDGE-01 : le dernier propriétaire ne peut pas quitter la page de son plein gré sans transfert préalable', function () {
    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/pages/{$this->page->id}/leave");

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['member']);

    expect($this->ownerMember->fresh()->status)->toBe(PageMemberStatus::Accepted);
});

test('EDGE-01 : le transfert de propriété est transactionnel et élève le nouveau propriétaire', function () {
    Passport::actingAs($this->owner);

    $response = $this->postJson("/api/pages/{$this->page->id}/transfer", [
        'new_owner_id' => $this->successor->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('data.owner.id', $this->successor->id);

    // Vérification en base
    expect((int) $this->page->fresh()->owner_id)->toBe($this->successor->id);

    // Le nouveau propriétaire a le rôle Owner
    $successorMembership = PageMember::where('page_id', $this->page->id)
        ->where('user_id', $this->successor->id)
        ->first();
    expect($successorMembership->role)->toBe(PageRole::Owner)
        ->and($successorMembership->status)->toBe(PageMemberStatus::Accepted);

    // L'ancien propriétaire a été rétrogradé en Éditeur
    expect($this->ownerMember->fresh()->role)->toBe(PageRole::Editor);
});

test('EDGE-03 : la suspension de certification révoque immédiatement le badge public sans détruire la page', function () {
    Passport::actingAs($this->admin);

    $response = $this->postJson("/api/admin/pages/{$this->page->id}/suspend-certification", [
        'reason' => 'Non-conformité fiscale détectée après audit',
    ]);

    $response->assertOk();

    $refreshed = $this->page->fresh();
    expect($refreshed->isCertified())->toBeFalse()
        ->and($refreshed->certification_status)->toBe(PageCertificationStatus::Suspended)
        ->and($refreshed->isActive())->toBeTrue();
});

test('EDGE-04 : la suspension opérationnelle d\'une page bloque les publications et modifications', function () {
    Passport::actingAs($this->admin);

    // 1. Suspension par l'admin
    $this->postJson("/api/admin/pages/{$this->page->id}/suspend", [
        'reason' => 'Signalements d\'escroquerie récurrents',
    ])->assertOk();

    expect($this->page->fresh()->isSuspended())->toBeTrue();

    // 2. Le propriétaire tente de modifier la page suspendue
    Passport::actingAs($this->owner);
    $resUpdate = $this->putJson("/api/pages/{$this->page->id}", [
        'name' => 'Tentative de modification',
    ]);
    $resUpdate->assertForbidden();

    // 3. L'éditeur tente de publier au nom de la page suspendue
    Passport::actingAs($this->editor);
    $resPublish = $this->postJson('/api/square-cr1/posts', [
        'body' => 'Post sur page suspendue',
        'as_page_id' => $this->page->id,
    ]);
    $resPublish->assertStatus(422);
});

test('Intermodule MS : un membre révoqué perd immédiatement l\'accès à l\'inbox professionnelle (EDGE-02)', function () {
    // Créer une conversation rattachée à la page
    $conversation = Conversation::create([
        'type' => ConversationType::Page,
        'page_id' => $this->page->id,
        'created_by_id' => $this->owner->id,
    ]);
    $conversation->members()->create([
        'user_id' => $this->editor->id,
        'role' => 'member',
    ]);

    // 1. L'éditeur actif peut accéder à l'inbox
    Passport::actingAs($this->editor);
    $resBefore = $this->getJson("/api/pages/{$this->page->id}/inbox");
    $resBefore->assertOk();

    // 2. Révocation de l'éditeur
    $this->editorMember->update([
        'status' => PageMemberStatus::Revoked,
        'revoked_at' => now(),
    ]);

    // 3. L'éditeur révoqué est immédiatement bloqué
    $resAfter = $this->getJson("/api/pages/{$this->page->id}/inbox");
    $resAfter->assertForbidden();

    // Et ne peut plus poster dans la conversation
    $resMsg = $this->postJson("/api/messages/threads/{$conversation->id}/messages", [
        'body' => 'Message interdit après révocation',
    ]);
    $resMsg->assertForbidden();
});

test('Intermodule RL : un Reel peut être rattaché à une Page et arborer le branding officiel', function () {
    $reel = Reel::factory()->create([
        'user_id' => $this->owner->id,
        'page_id' => $this->page->id,
        'caption' => 'Visite guidée des locaux de l\'entreprise',
    ]);

    Passport::actingAs($this->owner);

    $response = $this->getJson("/api/reels/{$reel->id}");
    $response->assertOk()
        ->assertJsonPath('data.is_page_reel', true)
        ->assertJsonPath('data.author_name', $this->page->name)
        ->assertJsonPath('data.author_badge', 'page_certified')
        ->assertJsonPath('data.page.id', $this->page->id);
});

test('Intermodule BO : un utilisateur peut sponsoriser une publication au nom de la Page (BO)', function () {
    // Provisionner le portefeuille de l'initiateur
    CreditWallet::factory()->create([
        'user_id' => $this->owner->id,
        'balance' => '50000.00',
        'currency' => 'XAF',
    ]);

    $post = Post::factory()->create([
        'user_id' => $this->owner->id,
        'page_id' => $this->page->id,
        'body' => 'Grande promotion de fin d\'année !',
    ]);

    Passport::actingAs($this->owner);

    $response = $this->postJson('/api/boost/campaigns', [
        'boostable_type' => 'post',
        'boostable_id' => (string) $post->id,
        'objective' => CampaignObjective::Reach->value,
        'budget' => 5000,
        'currency' => 'XAF',
        'duration_days' => 7,
        'payment_method' => 'credit',
        'targeting' => [
            'city' => 'Douala',
        ],
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.page_id', $this->page->id)
        ->assertJsonPath('data.sponsored_by', "Sponsorisé par {$this->page->name}");
});
