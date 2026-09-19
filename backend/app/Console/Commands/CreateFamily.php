<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\Family;
use App\Models\Plan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

#[Signature('family:create
    {slug : Identificativo univoco della famiglia (es. giopellino)}
    {name : Nome visualizzato della famiglia}
    {--admin-email= : Email dell\'amministratore della famiglia}
    {--admin-name= : Nome dell\'amministratore}
    {--app-url= : URL del frontend della famiglia, usato nei link di invito (es. https://giopellino.it)}
    {--plan= : Slug del piano (default: piano di default)}')]
#[Description('Crea una famiglia con il suo primo amministratore')]
class CreateFamily extends Command
{
    public function handle(): int
    {
        $email = $this->option('admin-email') ?: $this->ask('Email dell\'amministratore');
        $adminName = $this->option('admin-name') ?: $this->ask('Nome dell\'amministratore', 'Admin');

        $generatedPassword = null;
        $password = $this->input->isInteractive() ? $this->secret('Password (vuota per generarla)') : null;

        if (! $password) {
            $password = $generatedPassword = Str::password(16, symbols: false);
        }

        $input = [
            'slug' => $this->argument('slug'),
            'name' => $this->argument('name'),
            'email' => is_string($email) ? mb_strtolower(trim($email)) : $email,
            'admin_name' => $adminName,
            'password' => $password,
            'app_url' => $this->option('app-url'),
            'plan' => $this->option('plan') ?: config('photodaily.default_plan'),
        ];

        $validator = Validator::make($input, [
            'slug' => ['required', 'alpha_dash:ascii', 'max:64', Rule::unique('families', 'slug')],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'admin_name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()],
            'app_url' => ['nullable', 'url'],
            'plan' => ['required', Rule::exists('plans', 'slug')],
        ]);

        if ($validator->fails()) {
            $this->components->bulletList($validator->errors()->all());

            return self::FAILURE;
        }

        [$family, $admin] = DB::transaction(function () use ($input) {
            $family = Family::create([
                'slug' => $input['slug'],
                'name' => $input['name'],
                'app_url' => $input['app_url'],
                'plan_id' => Plan::query()->where('slug', $input['plan'])->value('id'),
            ]);

            $admin = $family->users()->create([
                'name' => $input['admin_name'],
                'email' => $input['email'],
                'password' => $input['password'],
                'role' => Role::Admin,
            ]);

            return [$family, $admin];
        });

        $this->components->info("Famiglia [{$family->slug}] creata (id {$family->id}).");
        $this->components->twoColumnDetail('Amministratore', $admin->email);
        $this->components->twoColumnDetail('Piano', $input['plan']);

        if ($generatedPassword) {
            $this->components->twoColumnDetail('Password generata', $generatedPassword);
            $this->components->warn('Conserva la password: non verrà mostrata di nuovo.');
        }

        return self::SUCCESS;
    }
}
