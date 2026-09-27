<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('user:super-admin
    {email : Email dell\'account}
    {--revoke : Toglie il permesso invece di darlo}')]
#[Description('Dà (o toglie) a un account l\'accesso al pannello del servizio su /admin')]
class SetSuperAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("Nessun account con email {$email}.");

            return self::FAILURE;
        }

        $user->forceFill(['is_super_admin' => ! $this->option('revoke')])->save();

        $this->components->info($user->is_super_admin
            ? "{$user->email} ora può entrare nel pannello del servizio (/admin sull'indirizzo principale)."
            : "{$user->email} non ha più accesso al pannello del servizio.");

        return self::SUCCESS;
    }
}
