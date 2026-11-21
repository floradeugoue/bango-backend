<?php

namespace Tests\Feature\Admin\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves operator IP from X-Forwarded-For when request comes through Cloudflare IPv4', function () {
    // 104.22.15.4 fait partie de la plage Cloudflare 104.16.0.0/13
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '104.22.15.4',
        'HTTP_X_FORWARDED_FOR' => '196.200.1.5',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '196.200.1.5');
});

it('resolves operator IP from 172.68.xx.xx Cloudflare range', function () {
    // 172.68.20.1 fait partie de la plage Cloudflare 172.64.0.0/13
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '172.68.20.1',
        'HTTP_X_FORWARDED_FOR' => '154.72.160.88',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '154.72.160.88');
});

it('resolves operator IP from CF-Connecting-IP header when request comes from Cloudflare', function () {
    // Cloudflare envoie CF-Connecting-IP avec l'adresse IP de l'opérateur
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '104.22.15.4',
        'HTTP_CF_CONNECTING_IP' => '196.200.1.5',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '196.200.1.5');
});

it('resolves operator IP when both CF-Connecting-IP and X-Forwarded-For are transmitted', function () {
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '162.158.10.5', // 162.158.0.0/15
        'HTTP_CF_CONNECTING_IP' => '196.200.1.5',
        'HTTP_X_FORWARDED_FOR' => '196.200.1.5',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '196.200.1.5');
});

it('resolves operator IP when request passes through Cloudflare IPv6', function () {
    // 2606:4700::1 fait partie de la plage Cloudflare 2606:4700::/32
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '2606:4700::1',
        'HTTP_X_FORWARDED_FOR' => '154.72.160.88',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '154.72.160.88');
});

it('does not trust spoofed X-Forwarded-For or CF-Connecting-IP from untrusted direct IP', function () {
    // 198.51.100.55 est une IP non-Cloudflare et non privée
    $response = $this->withServerVariables([
        'REMOTE_ADDR' => '198.51.100.55',
        'HTTP_X_FORWARDED_FOR' => '1.1.1.1',
        'HTTP_CF_CONNECTING_IP' => '1.1.1.1',
    ])->getJson('/admin/auth/context');

    $response->assertStatus(200)
        ->assertJsonPath('terminal.ip', '198.51.100.55'); // Conserve la vraie REMOTE_ADDR
});
