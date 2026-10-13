<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Administrateur', 'description' => 'Accès complet au système']
        );

        Role::firstOrCreate(
            ['slug' => 'user'],
            ['name' => 'Utilisateur', 'description' => 'Utilisateur standard']
        );
    }
}
