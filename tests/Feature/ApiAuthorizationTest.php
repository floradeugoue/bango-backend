<?php

use App\Models\Role;
use App\Models\User;
use Laravel\Passport\Passport;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RoleSeeder']);
});

it('allows public access without token', function () {
    $response = $this->getJson('/api/geo/countries');
    $response->assertStatus(200);
});

it('denies access to protected routes without token', function () {
    $response = $this->getJson('/api/profile');
    $response->assertStatus(401);
});

it('allows user access with passport token and user role', function () {
    $user = clone new User();
    $user->name = 'Normal User';
    $user->email = 'user@example.com';
    $user->password = bcrypt('password');
    $user->role_id = Role::where('slug', 'user')->first()->id;
    $user->save();

    Passport::actingAs($user);

    $response = $this->getJson('/api/profile');
    $response->assertStatus(200);
});

it('denies admin access to user with passport token and user role', function () {
    $user = clone new User();
    $user->name = 'Normal User';
    $user->email = 'user2@example.com';
    $user->password = bcrypt('password');
    $user->role_id = Role::where('slug', 'user')->first()->id;
    $user->save();

    Passport::actingAs($user);

    $response = $this->getJson('/api/roles');
    $response->assertStatus(403);
});

it('allows admin access with passport token and admin role', function () {
    $admin = clone new User();
    $admin->name = 'Admin User';
    $admin->email = 'admin@example.com';
    $admin->password = bcrypt('password');
    $admin->role_id = Role::where('slug', 'admin')->first()->id;
    $admin->save();

    Passport::actingAs($admin);

    $response = $this->getJson('/api/roles');
    $response->assertStatus(200);
});
