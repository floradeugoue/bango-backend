<?php

use App\Enums\Message\ConversationType;
use App\Models\Message\Conversation;
use App\Models\Message\ConversationMember;
use App\Models\Profile\BirthdayNotificationLog;
use App\Models\User;
use App\Notifications\Profile\BirthdayFriendNotification;
use App\Notifications\Profile\BirthdaySelfNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

test('1. birthday_visibility defaults to none, is exposed in /auth/me and can be updated via privacy settings and profile', function () {
    $user = User::factory()->create([
        'name' => 'Sophie Dupont',
        'display_name' => 'Sophie Dupont',
        'birth_date' => '1995-04-15',
    ]);

    Passport::actingAs($user);

    // Default value in /auth/me
    $resMe = $this->getJson('/api/auth/me');
    $resMe->assertOk()
        ->assertJsonPath('data.birthday_visibility', 'none')
        ->assertJsonPath('data.birthdayVisibility', 'none')
        ->assertJsonPath('data.birthDate', '1995-04-15');

    // Default value in /profile/privacy
    $resPrivacy = $this->getJson('/api/profile/privacy');
    $resPrivacy->assertOk()
        ->assertJsonPath('birthday_visibility', 'none')
        ->assertJsonPath('birthdayVisibility', 'none');

    // Update to mutuals via PUT /profile/privacy
    $updatePrivacy = $this->putJson('/api/profile/privacy', [
        'birthday_visibility' => 'mutuals',
    ]);
    $updatePrivacy->assertOk()
        ->assertJsonPath('birthday_visibility', 'mutuals')
        ->assertJsonPath('birthdayVisibility', 'mutuals');

    expect($user->fresh()->birthday_visibility)->toBe('mutuals');

    // Update to none via PUT /profile
    $updateProfile = $this->putJson('/api/profile', [
        'birthday_visibility' => 'none',
    ]);
    $updateProfile->assertOk()
        ->assertJsonPath('user.birthday_visibility', 'none');

    expect($user->fresh()->birthday_visibility)->toBe('none');
});

test('2. PublicProfileResource reveals birthday (day and month only, never year) only to mutuals when visibility is mutuals', function () {
    $alice = User::factory()->create([
        'name' => 'Alice Martin',
        'birth_date' => '1992-07-24',
        'birthday_visibility' => 'mutuals',
    ]);

    $bobMutual = User::factory()->create(['name' => 'Bob Mutual']);
    $charlieOneWay = User::factory()->create(['name' => 'Charlie OneWay']);

    // Alice <-> Bob are mutual followers
    $alice->following()->attach($bobMutual->id);
    $bobMutual->following()->attach($alice->id);

    // Charlie follows Alice, but Alice does NOT follow Charlie
    $charlieOneWay->following()->attach($alice->id);

    // 1. Bob (mutual) views Alice's profile
    Passport::actingAs($bobMutual);
    $resBob = $this->getJson("/api/profile/public/{$alice->id}");
    $resBob->assertOk()
        ->assertJsonPath('data.birthday', '24/07') // Only day/month!
        ->assertJsonMissing(['1992-07-24']);

    // 2. Charlie (not mutual) views Alice's profile
    Passport::actingAs($charlieOneWay);
    $resCharlie = $this->getJson("/api/profile/public/{$alice->id}");
    $resCharlie->assertOk()
        ->assertJsonPath('data.birthday', null);

    // 3. When birthday_visibility is none, even mutuals cannot see it
    $alice->update(['birthday_visibility' => 'none']);
    Passport::actingAs($bobMutual);
    $resBobNone = $this->getJson("/api/profile/public/{$alice->id}");
    $resBobNone->assertOk()
        ->assertJsonPath('data.birthday', null);
});

test('3. birthdays:notify sends birthday_self to user with first name at 9h local time', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'Marc Kamga',
        'display_name' => 'Marc Kamga',
        'birth_date' => '1990-11-21',
        'country_code' => 'CM',
        'city' => 'Douala',
        'birthday_visibility' => 'none',
    ]);

    // Reference date: 2026-11-21 (current test date, today is Marc's birthday)
    // Run command for 9h
    Artisan::call('birthdays:notify', [
        '--date' => '2026-11-21',
        '--hour' => 9,
        '--force' => true,
    ]);

    Notification::assertSentTo($user, BirthdaySelfNotification::class, function ($notification) use ($user) {
        $array = $notification->toArray($user);

        return $array['type'] === 'birthday_self'
            && $array['user_id'] === $user->id
            && $array['message'] === "Joyeux anniversaire, Marc ! Toute l'équipe BANGO te souhaite une belle journée.";
    });

    // Check log recorded in DB
    expect(BirthdayNotificationLog::where('user_id', $user->id)->where('type', 'self')->where('year', 2026)->count())->toBe(1);
});

test('4. birthdays:notify sends birthday_friend to mutual followers with conversation_id when visibility is mutuals', function () {
    Notification::fake();

    $birthdayPerson = User::factory()->create([
        'name' => 'Sophie Kamdem',
        'display_name' => 'Sophie Kamdem',
        'birth_date' => '1998-11-21',
        'birthday_visibility' => 'mutuals',
        'city' => 'Douala',
        'country_code' => 'CM',
    ]);

    $mutualFriend = User::factory()->create(['name' => 'Paul Biya', 'display_name' => 'Paul Biya']);
    $oneWayFollower = User::factory()->create(['name' => 'Inconnu']);

    // Mutual follow between birthdayPerson and mutualFriend
    $birthdayPerson->following()->attach($mutualFriend->id);
    $mutualFriend->following()->attach($birthdayPerson->id);

    // One-way follow from oneWayFollower
    $oneWayFollower->following()->attach($birthdayPerson->id);

    // Create an existing direct conversation between birthdayPerson and mutualFriend
    $conversation = Conversation::create([
        'type' => ConversationType::Direct,
        'created_by_id' => $birthdayPerson->id,
    ]);
    ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $birthdayPerson->id]);
    ConversationMember::create(['conversation_id' => $conversation->id, 'user_id' => $mutualFriend->id]);

    Artisan::call('birthdays:notify', [
        '--date' => '2026-11-21',
        '--hour' => 9,
        '--force' => true,
    ]);

    // 1. Birthday person gets birthday_self
    Notification::assertSentTo($birthdayPerson, BirthdaySelfNotification::class);

    // 2. Mutual friend gets birthday_friend with conversation_id
    Notification::assertSentTo($mutualFriend, BirthdayFriendNotification::class, function ($notification) use ($birthdayPerson, $mutualFriend, $conversation) {
        $array = $notification->toArray($mutualFriend);

        return $array['type'] === 'birthday_friend'
            && $array['user_id'] === $birthdayPerson->id
            && $array['conversation_id'] === $conversation->id
            && $array['message'] === "C'est l'anniversaire de Sophie Kamdem aujourd'hui. Souhaite-le-lui !";
    });

    // 3. One-way follower does NOT receive birthday_friend
    Notification::assertNotSentTo($oneWayFollower, BirthdayFriendNotification::class);
});

test('5. Idempotence: birthdays:notify does not notify more than once per year per person', function () {
    Notification::fake();

    $user = User::factory()->create([
        'name' => 'David',
        'birth_date' => '1995-11-21',
        'birthday_visibility' => 'none',
        'city' => 'Douala',
    ]);

    // First run
    Artisan::call('birthdays:notify', [
        '--date' => '2026-11-21',
        '--force' => true,
    ]);

    Notification::assertSentTimes(BirthdaySelfNotification::class, 1);

    // Second run on the same day
    Artisan::call('birthdays:notify', [
        '--date' => '2026-11-21',
        '--force' => true,
    ]);

    // Still only 1 notification sent!
    Notification::assertSentTimes(BirthdaySelfNotification::class, 1);
});

test('6. Suspended, deleted, or blocked accounts are excluded from notifications', function () {
    Notification::fake();

    // 1. Suspended birthday person
    $suspendedUser = User::factory()->create([
        'birth_date' => '1995-11-21',
        'status' => 'suspended',
        'city' => 'Douala',
    ]);

    // 2. Deleted / pending deletion birthday person
    $deletedUser = User::factory()->create([
        'birth_date' => '1995-11-21',
        'scheduled_for_deletion_at' => now()->addDays(20),
        'city' => 'Douala',
    ]);

    // 3. Active user who blocked mutual friend
    $activeUser = User::factory()->create([
        'name' => 'Christian',
        'birth_date' => '1995-11-21',
        'birthday_visibility' => 'mutuals',
        'city' => 'Douala',
    ]);
    $blockedFriend = User::factory()->create();
    $activeUser->following()->attach($blockedFriend->id);
    $blockedFriend->following()->attach($activeUser->id);
    $activeUser->blockedUsers()->attach($blockedFriend->id); // blocked!

    Artisan::call('birthdays:notify', [
        '--date' => '2026-11-21',
        '--force' => true,
    ]);

    Notification::assertNotSentTo($suspendedUser, BirthdaySelfNotification::class);
    Notification::assertNotSentTo($deletedUser, BirthdaySelfNotification::class);
    Notification::assertNotSentTo($blockedFriend, BirthdayFriendNotification::class);
    Notification::assertSentTo($activeUser, BirthdaySelfNotification::class);
});

test('7. February 29 birthday rule: celebrated on February 28 on non-leap years, February 29 on leap years', function () {
    $leapBornUser = User::factory()->create([
        'birth_date' => '2000-02-29',
    ]);

    // Year 2026 is non-leap year (not divisible by 4)
    expect(Carbon::create(2026, 1, 1)->isLeapYear())->toBeFalse();

    // On 2026-02-28, user born on Feb 29 SHOULD be celebrated
    expect($leapBornUser->isBirthdayOnDate(Carbon::create(2026, 2, 28)))->toBeTrue();

    // On 2026-03-01, should NOT be celebrated
    expect($leapBornUser->isBirthdayOnDate(Carbon::create(2026, 3, 1)))->toBeFalse();

    // On 2026-02-27, should NOT be celebrated
    expect($leapBornUser->isBirthdayOnDate(Carbon::create(2026, 2, 27)))->toBeFalse();

    // Year 2028 is a leap year (2028 % 4 == 0)
    expect(Carbon::create(2028, 1, 1)->isLeapYear())->toBeTrue();

    // On 2028-02-28 (leap year), user born on Feb 29 should NOT be celebrated yet
    expect($leapBornUser->isBirthdayOnDate(Carbon::create(2028, 2, 28)))->toBeFalse();

    // On 2028-02-29 (leap year), user born on Feb 29 SHOULD be celebrated
    expect($leapBornUser->isBirthdayOnDate(Carbon::create(2028, 2, 29)))->toBeTrue();
});
