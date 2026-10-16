<?php

use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('system.versions.android', [
        'min_version' => '1.5.0',
        'latest_version' => '2.0.0',
        'store_url' => 'https://play.google.com/store/apps/details?id=com.bango.app',
    ]);

    Config::set('system.versions.ios', [
        'min_version' => '1.4.0',
        'latest_version' => '1.9.0',
        'store_url' => 'https://apps.apple.com/app/bango/id000000000',
    ]);
});

test('it returns version requirements for android with default platform', function () {
    $response = $this->getJson('/api/system/version');

    $response->assertOk()
        ->assertJson([
            'data' => [
                'platform' => 'android',
                'min_version' => '1.5.0',
                'latest_version' => '2.0.0',
                'force_update' => false,
                'store_url' => 'https://play.google.com/store/apps/details?id=com.bango.app',
            ],
        ]);
});

test('it forces update when client version is strictly lower than min_version', function () {
    $response = $this->getJson('/api/system/version?platform=android&version=1.4.9');

    $response->assertOk()
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.force_update', true)
        ->assertJsonPath('data.min_version', '1.5.0')
        ->assertJsonPath('data.latest_version', '2.0.0');
});

test('it does not force update when client version equals min_version', function () {
    $response = $this->getJson('/api/system/version?platform=android&version=1.5.0');

    $response->assertOk()
        ->assertJsonPath('data.force_update', false)
        ->assertJsonPath('data.update_recommended', true);
});

test('it does not force update when client version is greater than min_version', function () {
    $response = $this->getJson('/api/system/version?platform=android&version=2.0.0');

    $response->assertOk()
        ->assertJsonPath('data.force_update', false)
        ->assertJsonPath('data.update_recommended', false);
});

test('it performs semantic comparison and not alphabetical comparison', function () {
    // Alphabetically "1.10.0" < "1.5.0", but semantically 1.10.0 > 1.5.0
    $response = $this->getJson('/api/system/version?platform=android&version=1.10.0');

    $response->assertOk()
        ->assertJsonPath('data.force_update', false);
});

test('it handles iOS platform and custom headers', function () {
    $response = $this->withHeaders([
        'X-App-Platform' => 'ios',
        'X-App-Version' => '1.3.9',
    ])->getJson('/api/system/version');

    $response->assertOk()
        ->assertJson([
            'data' => [
                'platform' => 'ios',
                'min_version' => '1.4.0',
                'latest_version' => '1.9.0',
                'force_update' => true,
                'store_url' => 'https://apps.apple.com/app/bango/id000000000',
            ],
        ]);
});

test('it rejects invalid platform with 422', function () {
    $response = $this->getJson('/api/system/version?platform=windowsphone');

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['platform']);
});

test('it handles malformed version strings without crashing', function () {
    $response = $this->getJson('/api/system/version?platform=android&version=not-a-valid-version-xyz');

    $response->assertOk()
        ->assertJsonPath('data.force_update', false);

    $responsePrefix = $this->getJson('/api/system/version?platform=android&version=v1.2.0-beta');
    $responsePrefix->assertOk()
        ->assertJsonPath('data.force_update', true);
});

test('it provides safe defaults when configuration is missing', function () {
    Config::set('system.versions.android', null);

    $response = $this->getJson('/api/system/version?platform=android&version=0.9.0');

    $response->assertOk()
        ->assertJsonPath('data.platform', 'android')
        ->assertJsonPath('data.min_version', '1.0.0')
        ->assertJsonPath('data.force_update', true);
});
