<?php

namespace App\Console\Commands\Admin;

use App\Models\Auth\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class CreateAdminCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'admin:create
                            {--name= : Le nom complet de l\'administrateur}
                            {--email= : L\'adresse email de l\'administrateur}
                            {--role=admin : Le slug du rôle (admin ou direction)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Crée de manière sécurisée un administrateur en demandant un mot de passe fort';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('--- Création sécurisée d\'un compte administrateur BANGO ---');

        $name = $this->option('name') ?: $this->ask('Nom complet');
        $email = $this->option('email') ?: $this->ask('Adresse email');
        $roleSlug = $this->option('role') ?: $this->choice('Rôle', ['admin', 'direction'], 0);

        // Validation des entrées de base
        $validator = Validator::make([
            'name' => $name,
            'email' => $email,
            'role' => $roleSlug,
        ], [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', 'in:admin,direction'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $role = Role::where('slug', $roleSlug)->first();
        if (! $role) {
            $this->error("Le rôle [{$roleSlug}] n'existe pas en base. Veuillez d'abord exécuter RoleSeeder.");

            return self::FAILURE;
        }

        // Demande interactive et masquée du mot de passe
        $password = $this->secret('Mot de passe (masqué)');
        $passwordConfirmation = $this->secret('Confirmation du mot de passe');

        if ($password !== $passwordConfirmation) {
            $this->error('Les mots de passe saisis ne correspondent pas.');

            return self::FAILURE;
        }

        // Validation de complexité du mot de passe (min 12 caractères, majuscule, minuscule, chiffre, symbole)
        $passwordValidator = Validator::make([
            'password' => $password,
        ], [
            'password' => [
                'required',
                'string',
                'min:12',
                'regex:/[a-z]/',
                'regex:/[A-Z]/',
                'regex:/[0-9]/',
                'regex:/[@$!%*#?&_\-\.]/',
            ],
        ], [
            'password.min' => 'Le mot de passe doit comporter au moins 12 caractères.',
            'password.regex' => 'Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.',
        ]);

        if ($passwordValidator->fails()) {
            foreach ($passwordValidator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'role_id' => $role->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $this->info("Compte administrateur [{$user->email}] créé avec succès avec le rôle [{$role->name}].");

        return self::SUCCESS;
    }
}
