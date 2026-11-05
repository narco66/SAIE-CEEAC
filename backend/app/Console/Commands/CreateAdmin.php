<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('saie:create-admin {email} {name} {--role=admin_tech : admin_tech ou admin_fonc}')]
#[Description('Crée un compte initial avec un mot de passe individuel à changer à la première connexion (LIV-03)')]
class CreateAdmin extends Command
{
    public function handle(Audit $audit): int
    {
        $role = Role::query()->where('code', $this->option('role'))->first();
        if (! $role || ! in_array($role->code, ['admin_tech', 'admin_fonc'], true)) {
            $this->error('Rôle invalide : admin_tech ou admin_fonc.');

            return self::FAILURE;
        }
        if (User::query()->where('email', mb_strtolower($this->argument('email')))->exists()) {
            $this->error('Un compte existe déjà avec cette adresse : aucune modification.');

            return self::FAILURE;
        }
        $password = Str::password(20);
        $user = User::query()->create(['name' => $this->argument('name'), 'email' => mb_strtolower($this->argument('email')), 'password' => $password, 'must_change_password' => true, 'status' => 'active']);
        RoleAssignment::query()->create(['user_id' => $user->id, 'role_id' => $role->id, 'starts_at' => now(), 'reason' => 'Compte initial créé en ligne de commande']);
        $audit->log('user.created_cli', $user, 'Commande saie:create-admin', actor: null);
        $this->info("Compte créé ({$role->name}). Mot de passe provisoire affiché une seule fois, à transmettre par un canal sûr :");
        $this->line($password);

        return self::SUCCESS;
    }
}
