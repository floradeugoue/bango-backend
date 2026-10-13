<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$user = User::forceCreate([
    'email' => 'kamenideugoue22@gmail.com',
    'name' => 'Kameni',
    'password' => bcrypt('password'),
    'handle' => 'kameni',
    'display_name' => 'Kameni',
    'phone' => '237658205891',
]);

echo 'User created: '.$user->id."\n";
