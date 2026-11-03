<?php

use App\Models\Listing\Listing;
use App\Models\User;
use App\Models\Visit\VisitReservation;
use App\Models\Visit\VisitSlot;
use App\Notifications\Auth\DeviceVerificationCodeNotification;
use App\Notifications\Auth\UnknownDeviceReportedNotification;
use App\Notifications\Social\SocialNotification;
use App\Notifications\Visit\VisitRequestedNotification;
use App\Services\Notification\NotificationPreferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('conserve toujours la notification dans la base de données (cloche de l\'app)', function () {
    $user = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => false,
                'email_enabled' => false,
                'sms_enabled' => false,
                'likes' => false,
                'comments' => false,
                'visits' => false,
            ],
        ],
    ]);

    $actor = User::factory()->create();
    $notification = new SocialNotification('like', $actor);

    $user->notify($notification);

    expect($user->notifications()->count())->toBe(1)
        ->and($user->unreadNotifications()->count())->toBe(1);
});

it('ne transmet pas de push si push_enabled est faux ou si le sujet est désactivé', function () {
    $preferenceService = app(NotificationPreferenceService::class);

    // 1. push_enabled = false (tout en pause)
    $pausedUser = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => false,
                'likes' => true,
                'comments' => true,
            ],
        ],
    ]);

    expect($preferenceService->isPushAllowed($pausedUser, 'like'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($pausedUser, 'comment'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($pausedUser, 'visit_requested'))->toBeFalse();

    // 2. push_enabled = true mais likes = false et comments = true
    $selectiveUser = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => true,
                'likes' => false,
                'comments' => true,
                'direct_messages' => false,
                'new_followers' => false,
                'mentions' => false,
                'stories' => false,
                'listings' => false,
                'visits' => false,
                'marketing' => false,
            ],
        ],
    ]);

    expect($preferenceService->isPushAllowed($selectiveUser, 'like'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'comment'))->toBeTrue()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'reply'))->toBeTrue()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'new_message'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'follow'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'mention'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'story_question_answer'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'new_matching_listing'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'visit_requested'))->toBeFalse()
        ->and($preferenceService->isPushAllowed($selectiveUser, 'campaign_activated'))->toBeFalse();
});

it('refuse l\'envoi par mail si email_enabled est faux ou si le sujet est désactivé', function () {
    $preferenceService = app(NotificationPreferenceService::class);

    // Utilisateur avec visites désactivées
    $userWithoutVisits = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'email_enabled' => true,
                'visits' => false,
                'listings' => true,
            ],
        ],
    ]);

    expect($preferenceService->isEmailAllowed($userWithoutVisits, 'visit_requested'))->toBeFalse()
        ->and($preferenceService->isEmailAllowed($userWithoutVisits, 'listing_decision'))->toBeTrue();

    // Utilisateur avec email_enabled = false
    $userNoEmail = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'email_enabled' => false,
                'visits' => true,
                'listings' => true,
            ],
        ],
    ]);

    expect($preferenceService->isEmailAllowed($userNoEmail, 'visit_requested'))->toBeFalse()
        ->and($preferenceService->isEmailAllowed($userNoEmail, 'listing_decision'))->toBeFalse();
});

it('envoie TOUJOURS les notifications de sécurité quel que soit le réglage', function () {
    $preferenceService = app(NotificationPreferenceService::class);

    // Utilisateur ayant tout coupé
    $userAllOff = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => false,
                'email_enabled' => false,
                'sms_enabled' => false,
            ],
        ],
    ]);

    $authNotification = new DeviceVerificationCodeNotification('123456');
    $deviceNotification = new UnknownDeviceReportedNotification('Nouvel appareil', 'hash123');

    // Vérification de l'exemption
    expect($preferenceService->isExempt($authNotification))->toBeTrue()
        ->and($preferenceService->isExempt($deviceNotification))->toBeTrue()
        ->and($preferenceService->isExempt('device_verification'))->toBeTrue()
        ->and($preferenceService->isExempt('unknown_device'))->toBeTrue()
        ->and($preferenceService->isExempt('otp'))->toBeTrue()
        ->and($preferenceService->isExempt('2fa'))->toBeTrue();

    // Vérification de l'autorisation forcée
    expect($preferenceService->isPushAllowed($userAllOff, $authNotification))->toBeTrue()
        ->and($preferenceService->isEmailAllowed($userAllOff, $authNotification))->toBeTrue()
        ->and($preferenceService->isSmsAllowed($userAllOff, $authNotification))->toBeTrue();

    // Les canaux pour une notification de sécurité contiennent database ET mail
    $channels = $preferenceService->channelsFor($userAllOff, $authNotification);
    expect($channels)->toContain('database')
        ->and($channels)->toContain('mail');
});

it('envoie TOUJOURS les notifications de paiement quel que soit le réglage', function () {
    $preferenceService = app(NotificationPreferenceService::class);

    $userAllOff = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'push_enabled' => false,
                'email_enabled' => false,
                'sms_enabled' => false,
            ],
        ],
    ]);

    expect($preferenceService->isExempt('payment_failed'))->toBeTrue()
        ->and($preferenceService->isExempt('renewal_upcoming'))->toBeTrue()
        ->and($preferenceService->isExempt('receipt'))->toBeTrue()
        ->and($preferenceService->isExempt('subscription_payment'))->toBeTrue();

    expect($preferenceService->isPushAllowed($userAllOff, 'payment_failed'))->toBeTrue()
        ->and($preferenceService->isEmailAllowed($userAllOff, 'payment_failed'))->toBeTrue()
        ->and($preferenceService->isPushAllowed($userAllOff, 'renewal_upcoming'))->toBeTrue()
        ->and($preferenceService->isEmailAllowed($userAllOff, 'renewal_upcoming'))->toBeTrue();
});

it('applique les canaux conditionnels via NotificationPreferenceService::channelsFor', function () {
    $preferenceService = app(NotificationPreferenceService::class);

    $userAllowed = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'email_enabled' => true,
                'visits' => true,
            ],
        ],
    ]);

    $userRefused = User::factory()->create([
        'privacy_settings' => [
            'notifications' => [
                'email_enabled' => true,
                'visits' => false,
            ],
        ],
    ]);

    // Simuler une notification de visite
    $landlord = User::factory()->create();
    $listing = Listing::factory()->create(['owner_id' => $landlord->id]);
    $slot = VisitSlot::create([
        'listing_id' => $listing->id,
        'owner_id' => $landlord->id,
        'creator_id' => $landlord->id,
        'start_at' => now()->addDay(),
        'end_at' => now()->addDay()->addHours(2),
        'max_visitors' => 5,
        'booked_count' => 0,
        'status' => 'open',
    ]);
    $reservation = VisitReservation::create([
        'listing_id' => $listing->id,
        'slot_id' => $slot->id,
        'prospect_id' => $userAllowed->id,
        'owner_id' => $landlord->id,
        'status' => 'confirmed',
        'fee' => 5000,
        'currency' => 'XAF',
    ]);

    $visitNotification = new VisitRequestedNotification($reservation);

    // L'utilisateur ayant visits=true a database et mail
    expect($preferenceService->channelsFor($userAllowed, $visitNotification))
        ->toEqual(['database', 'mail']);

    // L'utilisateur ayant visits=false n'a QUE database
    expect($preferenceService->channelsFor($userRefused, $visitNotification))
        ->toEqual(['database']);
});
