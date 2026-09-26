<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('user:set-password {email : Email dell\'account}')]
#[Description('Imposta una nuova password per un account (la chiede senza mostrarla) e chiude le sessioni aperte')]
class SetUserPassword extends Command
{
    public function handle(): int
    {
        $user = User::query()->where('email', mb_strtolower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->components->error('Nessun account con questa email.');

            return self::FAILURE;
        }

        // La password si scrive solo qui, nascosta: niente argomenti o opzioni,
        // che resterebbero nella cronologia della shell.
        $password = (string) $this->secret('Nuova password');
        $confirmation = (string) $this->secret('Ripetila');

        $validator = Validator::make(
            ['password' => $password, 'password_confirmation' => $confirmation],
            ['password' => ['required', 'confirmed', Password::defaults()]],
            ['password.confirmed' => 'Le due password non coincidono.'],
        );

        if ($validator->fails()) {
            $this->components->bulletList($validator->errors()->all());

            return self::FAILURE;
        }

        $user->forceFill(['password' => $password])->save();

        // Chi conosceva la vecchia password non resta dentro da nessun dispositivo.
        $revoked = $user->tokens()->delete();

        $this->components->info("Password aggiornata per {$user->email} ({$user->family->slug}). Sessioni chiuse: {$revoked}.");

        return self::SUCCESS;
    }
}
