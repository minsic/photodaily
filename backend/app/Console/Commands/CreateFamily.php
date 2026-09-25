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
    {slug : Identificativo univoco, diventa il sottodominio (es. giopellino)}
    {name : Nome visualizzato della famiglia}
    {--admin-email= : Email dell\'amministratore della famiglia}
    {--admin-name= : Nome dell\'amministratore}
    {--domain= : Dominio proprio della famiglia, oltre al sottodominio (es. giopellino.it)}
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
            'custom_domain' => is_string($this->option('domain')) ? Family::normalizeHost($this->option('domain')) : null,
            'plan' => $this->option('plan') ?: config('photodaily.default_plan'),
        ];

        $validator = Validator::make($input, [
            // Lo slug è un'etichetta DNS: minuscole, cifre e trattini, non ai bordi.
            'slug' => [
                'required', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/',
                Rule::notIn(Family::RESERVED_SLUGS), Rule::unique('families', 'slug'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'admin_name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()],
            'custom_domain' => [
                'nullable', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/',
                'not_regex:/(^|\.)'.preg_quote(Family::mainHost(), '/').'$/',
                Rule::unique('families', 'custom_domain'),
            ],
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
                'custom_domain' => $input['custom_domain'],
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
        $this->components->twoColumnDetail('Indirizzo', $family->url());
        $this->components->twoColumnDetail('Piano', $input['plan']);

        if ($generatedPassword) {
            $this->components->twoColumnDetail('Password generata', $generatedPassword);
            $this->components->warn('Conserva la password: non verrà mostrata di nuovo.');
        }

        return self::SUCCESS;
    }
}
