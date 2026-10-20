<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('point 47: GET and PUT /api/settings/notifications manage user notification preferences', function () {
    $user = User::factory()->create();

    // 1. GET returns default preferences
    $resGet = $this->actingAs($user, 'api')->getJson('/api/settings/notifications');
    $resGet->assertOk();
    expect($resGet->json('push_enabled'))->toBeTrue();
    expect($resGet->json('likes'))->toBeTrue();
    expect($resGet->json('comments'))->toBeTrue();

    // 2. PUT updates preferences
    $resPut = $this->actingAs($user, 'api')->putJson('/api/settings/notifications', [
        'push_enabled' => false,
        'comments' => false,
        'marketing' => true,
    ]);

    $resPut->assertOk();
    expect($resPut->json('push_enabled'))->toBeFalse();
    expect($resPut->json('comments'))->toBeFalse();
    expect($resPut->json('marketing'))->toBeTrue();
    expect($resPut->json('likes'))->toBeTrue(); // unchanged

    // 3. User model persisted
    $user->refresh();
    expect($user->privacy_settings['notifications']['push_enabled'])->toBeFalse();
    expect($user->privacy_settings['notifications']['comments'])->toBeFalse();
    expect($user->privacy_settings['notifications']['marketing'])->toBeTrue();

    // 4. GET now returns updated preferences
    $resGet2 = $this->actingAs($user, 'api')->getJson('/api/settings/notifications');
    $resGet2->assertOk();
    expect($resGet2->json('push_enabled'))->toBeFalse();
    expect($resGet2->json('comments'))->toBeFalse();
});
