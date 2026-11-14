<?php

use App\Enums\Delegation\DelegationCapability;
use App\Enums\Delegation\DelegationStatus;
use App\Enums\Page\PageMemberStatus;
use App\Enums\Page\PageRole;
use App\Enums\Page\PageStatus;
use App\Models\Delegation\Delegation;
use App\Models\Listing\Listing;
use App\Models\Message\Conversation;
use App\Models\Page\Page;
use App\Models\Page\PageMember;
use App\Models\User;
use App\Notifications\Delegation\AgentAcceptedNotification;
use App\Notifications\Delegation\AgentDeclinedNotification;
use App\Services\Message\MessageThreadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test', '--no-interaction' => true]);
    Cache::flush();
});

test('GET /api/messages/requests filtre les demandes par identite active compte ou page et inclut page_id', function () {
    $personalUser = User::factory()->create(['name' => 'alice', 'status' => 'active']);
    $pageOwner = User::factory()->create(['name' => 'bob', 'status' => 'active']);
    $outsider1 = User::factory()->create(['name' => 'client1', 'status' => 'active']);
    $outsider2 = User::factory()->create(['name' => 'client2', 'status' => 'active']);

    $page = Page::create([
        'owner_id' => $pageOwner->id,
        'name' => 'Agence Immobiliere Centrale',
        'handle' => 'agence_centrale',
        'status' => PageStatus::Active,
    ]);

    /** @var MessageThreadService $threadService */
    $threadService = app(MessageThreadService::class);

    // 1. Demande de message adressée au compte personnel de bob (sans page)
    $personalConversation = $threadService->getOrCreateDirectConversation($outsider1, $pageOwner->id);
    expect($personalConversation->status)->toBe('pending')
        ->and($personalConversation->page_id)->toBeNull();

    // 2. Demande de message adressée à la Page de bob
    $pageConversation = Conversation::create([
        'type' => 'page',
        'status' => 'pending',
        'page_id' => $page->id,
        'created_by_id' => $outsider2->id,
        'title' => $page->name,
    ]);

    // Cas A : Bob consulte ses demandes SANS en-tête X-Bango-Page (compte personnel)
    Passport::actingAs($pageOwner);
    $responsePersonal = $this->getJson('/api/messages/requests');
    $responsePersonal->assertOk();

    $dataPersonal = $responsePersonal->json('data');
    expect(count($dataPersonal))->toBe(1)
        ->and($dataPersonal[0]['id'])->toBe((string) $personalConversation->id)
        ->and($dataPersonal[0]['page_id'])->toBeNull();

    // Cas B : Bob consulte ses demandes AVEC X-Bango-Page (boîte de la page)
    $responsePage = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/messages/requests');
    $responsePage->assertOk();

    $dataPage = $responsePage->json('data');
    expect(count($dataPage))->toBe(1)
        ->and($dataPage[0]['id'])->toBe((string) $pageConversation->id)
        ->and($dataPage[0]['page_id'])->toBe((int) $page->id)
        ->and($dataPage[0]['page']['id'])->toBe((int) $page->id)
        ->and($dataPage[0]['page']['name'])->toBe('Agence Immobiliere Centrale');

    // Cas C : Un membre actif de la page peut aussi consulter les demandes de la page
    $editor = User::factory()->create(['name' => 'charlie', 'status' => 'active']);
    PageMember::create([
        'page_id' => $page->id,
        'user_id' => $editor->id,
        'role' => PageRole::Editor,
        'status' => PageMemberStatus::Accepted->value,
    ]);

    Passport::actingAs($editor);
    $responseEditorPage = $this->withHeader('X-Bango-Page', (string) $page->id)
        ->getJson('/api/messages/requests');
    $responseEditorPage->assertOk();
    expect(count($responseEditorPage->json('data')))->toBe(1)
        ->and($responseEditorPage->json('data.0.id'))->toBe((string) $pageConversation->id);

    // Cas D : Sans le header X-Bango-Page, Charlie n'a aucune demande personnelle
    $this->flushHeaders();
    $responseEditorPersonal = $this->getJson('/api/messages/requests');
    $responseEditorPersonal->assertOk();
    expect($responseEditorPersonal->json('data'))->toBeEmpty();
});

test('AgentAcceptedNotification utilise display_name et regroupe le nombre de biens sans spammer', function () {
    $landlord = User::factory()->create([
        'name' => 'landlord_user',
        'display_name' => 'Bailleur Paul',
        'badge' => 'landlord',
        'status' => 'active',
    ]);

    $agent = User::factory()->create([
        'name' => 'rodrigue',
        'display_name' => 'Rodrigue Tagne',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
    ]);

    $invitationTime = now()->subMinute();

    // Création de 7 biens et 7 invitations émis en lot
    $delegations = [];
    for ($i = 1; $i <= 7; $i++) {
        $listing = Listing::factory()->published()->create([
            'owner_id' => $landlord->id,
            'title' => "Appartement {$i}",
        ]);

        $delegations[] = Delegation::create([
            'bailleur_id' => $landlord->id,
            'agent_id' => $agent->id,
            'listing_id' => $listing->id,
            'status' => DelegationStatus::Pending,
            'capabilities' => [DelegationCapability::Visits->value, DelegationCapability::Reply->value],
            'invited_at' => $invitationTime,
        ]);
    }

    Passport::actingAs($agent);

    // L'agent accepte les 7 biens (simulation de la boucle frontend de l'application)
    foreach ($delegations as $delegation) {
        $res = $this->postJson("/api/agent/invitations/{$delegation->id}/accept");
        $res->assertOk();
    }

    // Vérification que tous les 7 mandats sont bien actifs
    foreach ($delegations as $delegation) {
        expect($delegation->fresh()->status)->toBe(DelegationStatus::Active);
    }

    // Vérification en base des notifications du bailleur : UNE SEULE notification envoyée !
    $notifications = $landlord->notifications()->where('type', AgentAcceptedNotification::class)->get();
    expect($notifications->count())->toBe(1);

    $notifData = $notifications->first()->data;
    expect($notifData['agent_name'])->toBe('Rodrigue Tagne')
        ->and($notifData['properties_count'])->toBe(7)
        ->and($notifData['message'])->toContain('Rodrigue Tagne a accepté de gérer 7 de vos biens');
});

test('AgentDeclinedNotification utilise display_name et regroupe le nombre de biens sans spammer', function () {
    $landlord = User::factory()->create([
        'name' => 'landlord_user',
        'display_name' => 'Bailleur Marc',
        'badge' => 'landlord',
        'status' => 'active',
    ]);

    $agent = User::factory()->create([
        'name' => 'rodrigue',
        'display_name' => 'Rodrigue Tagne',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
    ]);

    $invitationTime = now()->subMinute();

    // Création de 5 biens et 5 invitations
    $delegations = [];
    for ($i = 1; $i <= 5; $i++) {
        $listing = Listing::factory()->published()->create([
            'owner_id' => $landlord->id,
            'title' => "Studio {$i}",
        ]);

        $delegations[] = Delegation::create([
            'bailleur_id' => $landlord->id,
            'agent_id' => $agent->id,
            'listing_id' => $listing->id,
            'status' => DelegationStatus::Pending,
            'capabilities' => [DelegationCapability::Visits->value],
            'invited_at' => $invitationTime,
        ]);
    }

    Passport::actingAs($agent);

    // L'agent refuse (boucle app sur les 5 biens)
    foreach ($delegations as $delegation) {
        $res = $this->postJson("/api/agent/invitations/{$delegation->id}/reject");
        $res->assertOk();
    }

    // Vérification que les 5 mandats sont passés en Declined
    foreach ($delegations as $delegation) {
        expect($delegation->fresh()->status)->toBe(DelegationStatus::Declined);
    }

    // Une seule notification au bailleur
    $notifications = $landlord->notifications()->where('type', AgentDeclinedNotification::class)->get();
    expect($notifications->count())->toBe(1);

    $notifData = $notifications->first()->data;
    expect($notifData['agent_name'])->toBe('Rodrigue Tagne')
        ->and($notifData['properties_count'])->toBe(5)
        ->and($notifData['message'])->toContain('Rodrigue Tagne a décliné la gestion de 5 de vos biens');
});

test('Acceptation par lot avec all=true active toutes les invitations du lot en un seul appel', function () {
    $landlord = User::factory()->create([
        'name' => 'landlord_user',
        'display_name' => 'Bailleur Jean',
        'badge' => 'landlord',
        'status' => 'active',
    ]);

    $agent = User::factory()->create([
        'name' => 'rodrigue',
        'display_name' => 'Rodrigue Tagne',
        'badge' => 'agent',
        'is_verified' => true,
        'verification_lapsed' => false,
        'status' => 'active',
    ]);

    $invitationTime = now()->subMinute();

    $delegations = [];
    for ($i = 1; $i <= 3; $i++) {
        $listing = Listing::factory()->published()->create([
            'owner_id' => $landlord->id,
            'title' => "Villa {$i}",
        ]);

        $delegations[] = Delegation::create([
            'bailleur_id' => $landlord->id,
            'agent_id' => $agent->id,
            'listing_id' => $listing->id,
            'status' => DelegationStatus::Pending,
            'capabilities' => [DelegationCapability::Visits->value],
            'invited_at' => $invitationTime,
        ]);
    }

    Passport::actingAs($agent);

    // Un seul appel avec all=true
    $res = $this->postJson("/api/agent/invitations/{$delegations[0]->id}/accept", ['all' => true]);
    $res->assertOk();

    foreach ($delegations as $delegation) {
        expect($delegation->fresh()->status)->toBe(DelegationStatus::Active);
    }

    $notifications = $landlord->notifications()->where('type', AgentAcceptedNotification::class)->get();
    expect($notifications->count())->toBe(1)
        ->and($notifications->first()->data['properties_count'])->toBe(3);
});
