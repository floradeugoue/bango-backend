<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrateur', 'description' => 'Accès complet au système']
        );

        \App\Models\Role::firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'Utilisateur', 'description' => 'Utilisateur standard']
        );
    }
}
