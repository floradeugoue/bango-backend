<?php

namespace Database\Seeders;

use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleSeeder::class,
            InterestSeeder::class,
            GeoSeeder::class,
            SubscriptionCatalogSeeder::class,
            SoundSeeder::class,
        ]);

        $adminRole = Role::where('slug', 'admin')->first();

        User::firstOrCreate(
            ['email' => 'test@example.com'],
            [
                'name' => 'Test User',
                'password' => bcrypt('password'),
                'role_id' => $adminRole?->id,
            ]
        );
    }
}
