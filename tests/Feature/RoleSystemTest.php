<?php

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds the admin and user roles', function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    expect(Role::count())->toBe(2);
    expect(Role::where('slug', 'admin')->exists())->toBeTrue();
    expect(Role::where('slug', 'user')->exists())->toBeTrue();
});

it('is idempotent and does not create duplicates', function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);

    expect(Role::count())->toBe(2);
});

it('assigns the user role by default to a new user during signup', function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    \Illuminate\Support\Facades\Artisan::call('passport:client', ['--personal' => true, '--name' => 'Test']);

    $response = $this->postJson('/api/auth/signup', [
        'name' => 'Test User',
        'email' => 'testrole@example.com',
        'password' => 'Password123!',
    ]);

    $response->assertStatus(201);
    
    $user = User::where('email', 'testrole@example.com')->first();
    expect($user->role->slug)->toBe('user');
    expect($user->hasRole('user'))->toBeTrue();
    expect($user->isAdmin())->toBeFalse();
});

it('validates role relationships', function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
    
    $role = Role::where('slug', 'user')->first();
    $user = User::factory()->create(['role_id' => $role->id]);

    expect($user->role->id)->toBe($role->id);
    expect($role->users->contains($user))->toBeTrue();
});
