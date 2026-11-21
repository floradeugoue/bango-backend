<?php

use App\Mail\Admin\AdminAccessRequestSubmittedMail;
use App\Models\Auth\Role;
use App\Models\User;
use App\Notifications\Admin\AdminAccessRequestPendingNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('sends receipt email to applicant and notifies admin/direction reviewers when access request is submitted', function () {
    Mail::fake();
    Notification::fake();

    // Retrieve or create direction and admin roles
    $directionRole = Role::firstOrCreate(
        ['slug' => 'direction'],
        ['name' => 'Direction']
    );
    $adminRole = Role::firstOrCreate(
        ['slug' => 'admin'],
        ['name' => 'Admin']
    );

    // Create an active direction user and an active admin user
    $director = User::factory()->create([
        'name' => 'Marie Directrice',
        'email' => 'marie.direction@bango.app',
        'role_id' => $directionRole->id,
        'status' => 'active',
    ]);

    $admin = User::factory()->create([
        'name' => 'Jean Admin',
        'email' => 'jean.admin@bango.app',
        'role_id' => $adminRole->id,
        'status' => 'active',
    ]);

    // Create standard user that should NOT be notified
    $standardUser = User::factory()->create([
        'name' => 'Utilisateur Standard',
        'email' => 'user@bango.africa',
        'role_id' => null,
        'status' => 'active',
    ]);

    $response = postJson('/api/admin/auth/access-requests', [
        'first_name' => 'Claude',
        'last_name' => 'Demandeur',
        'email' => 'claude.demandeur@bango.app',
        'department' => 'Opérations',
        'requested_role' => 'support',
        'reason' => 'Prise de poste au service support client',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'message',
            'reference_id',
            'reference',
            'status',
        ]);

    $accessRequestId = $response->json('reference_id');
    $expectedRef = "ACC-{$accessRequestId}";

    expect($response->json('reference'))->toBe($expectedRef);

    // 1. Assert acknowledgment mail sent to the applicant
    Mail::assertSent(AdminAccessRequestSubmittedMail::class, function ($mail) use ($accessRequestId, $expectedRef) {
        return $mail->hasTo('claude.demandeur@bango.app')
            && $mail->accessRequest->id === $accessRequestId
            && $mail->reference === $expectedRef
            && $mail->expectedDelayFr === '48 h ouvrées';
    });

    // 2. Assert notification sent to reviewers (director and admin)
    Notification::assertSentTo(
        [$director, $admin],
        AdminAccessRequestPendingNotification::class,
        function ($notification) use ($accessRequestId, $expectedRef) {
            return $notification->accessRequest->id === $accessRequestId
                && $notification->accessRequest->reference === $expectedRef;
        }
    );

    // Assert NOT sent to regular user
    Notification::assertNotSentTo($standardUser, AdminAccessRequestPendingNotification::class);
});
