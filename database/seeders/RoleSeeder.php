<?php

namespace Database\Seeders;

use App\Enums\Admin\AdminPermission;
use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $roles = [
            [
                'slug' => 'admin',
                'name' => 'Administrateur',
                'description' => 'Accès complet au système',
                'permissions' => AdminPermission::all(),
            ],
            [
                'slug' => 'direction',
                'name' => 'Direction',
                'description' => 'Direction générale et supervision transversale',
                'permissions' => AdminPermission::defaultForRole('direction'),
            ],
            [
                'slug' => 'moderation',
                'name' => 'Modération',
                'description' => 'Modération du contenu et des signalements',
                'permissions' => AdminPermission::defaultForRole('moderation'),
            ],
            [
                'slug' => 'support',
                'name' => 'Support Client',
                'description' => 'Assistance aux utilisateurs et gestion des tickets',
                'permissions' => AdminPermission::defaultForRole('support'),
            ],
            [
                'slug' => 'finance',
                'name' => 'Finance',
                'description' => 'Gestion financière, litiges et remboursements',
                'permissions' => AdminPermission::defaultForRole('finance'),
            ],
            [
                'slug' => 'verification',
                'name' => 'Vérification',
                'description' => 'Contrôle d\'identité KYC et conformité',
                'permissions' => AdminPermission::defaultForRole('verification'),
            ],
            [
                'slug' => 'user',
                'name' => 'Utilisateur',
                'description' => 'Utilisateur standard',
                'permissions' => [],
            ],
        ];

        foreach ($roles as $roleData) {
            Role::updateOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                    'permissions' => $roleData['permissions'],
                    'is_active' => true,
                ]
            );
        }

        $moderationRole = Role::where('slug', 'moderation')->first();
        if ($moderationRole) {
            User::firstOrCreate(
                ['email' => 'fatou.diop@bango.app'],
                [
                    'name' => 'Fatou Diop',
                    'password' => bcrypt('AdminPassword123!'),
                    'role_id' => $moderationRole->id,
                    'status' => 'active',
                ]
            );
        }
    }
}
