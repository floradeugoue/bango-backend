<?php

use App\Enums\Admin\AdminPermission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $roles = [
            [
                'slug' => 'admin',
                'name' => 'Administrateur',
                'description' => 'Accès complet au système',
                'permissions' => json_encode(AdminPermission::all()),
                'is_active' => true,
            ],
            [
                'slug' => 'direction',
                'name' => 'Direction',
                'description' => 'Direction générale et supervision transversale',
                'permissions' => json_encode(AdminPermission::defaultForRole('direction')),
                'is_active' => true,
            ],
            [
                'slug' => 'moderation',
                'name' => 'Modération',
                'description' => 'Modération du contenu et des signalements',
                'permissions' => json_encode(AdminPermission::defaultForRole('moderation')),
                'is_active' => true,
            ],
            [
                'slug' => 'support',
                'name' => 'Support Client',
                'description' => 'Assistance aux utilisateurs et gestion des tickets',
                'permissions' => json_encode(AdminPermission::defaultForRole('support')),
                'is_active' => true,
            ],
            [
                'slug' => 'finance',
                'name' => 'Finance',
                'description' => 'Gestion financière, litiges et remboursements',
                'permissions' => json_encode(AdminPermission::defaultForRole('finance')),
                'is_active' => true,
            ],
            [
                'slug' => 'verification',
                'name' => 'Vérification',
                'description' => 'Contrôle d\'identité KYC et conformité',
                'permissions' => json_encode(AdminPermission::defaultForRole('verification')),
                'is_active' => true,
            ],
            [
                'slug' => 'user',
                'name' => 'Utilisateur',
                'description' => 'Utilisateur standard',
                'permissions' => json_encode([]),
                'is_active' => true,
            ],
        ];

        foreach ($roles as $role) {
            $existing = DB::table('roles')->where('slug', $role['slug'])->first();
            if ($existing) {
                DB::table('roles')->where('slug', $role['slug'])->update([
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'permissions' => $role['permissions'],
                    'is_active' => $role['is_active'],
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('roles')->insert([
                    'slug' => $role['slug'],
                    'name' => $role['name'],
                    'description' => $role['description'],
                    'permissions' => $role['permissions'],
                    'is_active' => $role['is_active'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop system roles to avoid breaking user foreign keys
    }
};
