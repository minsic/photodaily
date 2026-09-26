<?php

namespace App\Console\Commands;

use App\Enums\AccessMode;
use App\Enums\Role;
use App\Models\Family;
use App\Models\Invite;
use App\Models\Plan;
use App\Notifications\FamilyInvitation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

#[Signature('family:create
    {slug : Diventa il sottodominio: minuscole, cifre e trattini, 3-30 caratteri (es. carozzi)}
    {name : Nome del diario (es. "Famiglia Carozzi")}
    {email : Email del primo amministratore: riceve l\'invito e sceglie lui la password}
    {--access-mode=private : private oppure public (la modalità con password si imposta dopo, dalle impostazioni)}
    {--plan= : Slug del piano (predefinito: quello di config photodaily.default_plan)}
    {--timezone=Europe/Rome : Fuso orario con cui si decide che giorno è "oggi"}
    {--domain= : Dominio proprio della famiglia, oltre al sottodominio (es. carozzi.it)}')]
#[Description('Crea una famiglia e l\'invito per il suo primo amministratore')]
class CreateFamily extends Command
{
    public function handle(): int
    {
        $input = [
            'slug' => $this->argument('slug'),
            'name' => $this->argument('name'),
            'email' => mb_strtolower(trim((string) $this->argument('email'))),
            'access_mode' => $this->option('access-mode'),
            'plan' => $this->option('plan') ?: config('photodaily.default_plan'),
            'timezone' => $this->option('timezone'),
            'custom_domain' => is_string($this->option('domain')) ? Family::normalizeHost($this->option('domain')) : null,
        ];

        $validator = Validator::make($input, [
            // Lo slug è un'etichetta DNS: minuscole, cifre e trattini, mai ai bordi.
            'slug' => [
                'required', 'regex:/^[a-z0-9][a-z0-9-]{1,28}[a-z0-9]$/',
                Rule::notIn(Family::reservedSlugs()), Rule::unique('families', 'slug'),
            ],
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required', 'email', 'max:255', Rule::unique('users', 'email'),
                Rule::unique('invites', 'email')->whereNull('accepted_at')->where(fn ($query) => $query->where('expires_at', '>', now())),
            ],
            // Con la password servirebbe scriverla qui: meglio dalle impostazioni.
            'access_mode' => ['required', Rule::in([AccessMode::Private->value, AccessMode::Public->value])],
            'plan' => ['required', Rule::exists('plans', 'slug')],
            'timezone' => ['required', 'timezone:all'],
            'custom_domain' => [
                'nullable', 'max:253', 'regex:/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/',
                'not_regex:/(^|\.)'.preg_quote(Family::mainHost(), '/').'$/',
                Rule::unique('families', 'custom_domain'),
            ],
        ], [
            'slug.regex' => 'Lo slug deve avere 3-30 caratteri fra minuscole, cifre e trattini, senza trattini ai bordi.',
            'slug.not_in' => 'Questo slug è riservato al servizio.',
            'slug.unique' => 'Esiste già una famiglia con questo slug.',
            'email.unique' => 'Questa email ha già un account o un invito in attesa.',
            'access_mode.in' => 'La modalità di accesso può essere private o public.',
            'timezone.timezone' => 'Fuso orario non riconosciuto.',
        ]);

        if ($validator->fails()) {
            $this->components->bulletList($validator->errors()->all());

            return self::FAILURE;
        }

        $token = Str::random(64);

        [$family, $invite] = DB::transaction(function () use ($input, $token) {
            $family = Family::create([
                'slug' => $input['slug'],
                'name' => $input['name'],
                'custom_domain' => $input['custom_domain'],
                'timezone' => $input['timezone'],
                'plan_id' => Plan::query()->where('slug', $input['plan'])->value('id'),
            ]);

            $family->changeAccessMode(AccessMode::from($input['access_mode']));

            // Niente password nel terminale: l'amministratore la sceglie dal link.
            $invite = $family->invites()->create([
                'email' => $input['email'],
                'role' => Role::Admin,
                'token' => Invite::hashToken($token),
                'invited_by' => null,
                'expires_at' => now()->addDays(config('photodaily.invite_ttl_days')),
            ]);

            return [$family, $invite];
        });

        $url = $family->inviteUrl($token);

        $this->components->info("Famiglia [{$family->slug}] creata (id {$family->id}).");
        $this->components->twoColumnDetail('Indirizzo', $family->url());
        $this->components->twoColumnDetail('Piano', $input['plan']);
        $this->components->twoColumnDetail('Accesso', $input['access_mode']);
        $this->components->twoColumnDetail('Fuso orario', $input['timezone']);
        $this->components->twoColumnDetail('Invito admin per', $invite->email);
        $this->components->twoColumnDetail('Scade il', $invite->expires_at->format('d/m/Y'));
        $this->newLine();
        $this->line("  Link dell'invito: <href={$url}>{$url}</>");
        $this->newLine();

        $this->sendInvite($invite, $url);

        return self::SUCCESS;
    }

    /**
     * Con un mailer vero l'invito parte anche per email; con log o array
     * (sviluppo, test) resta il link stampato sopra.
     */
    private function sendInvite(Invite $invite, string $url): void
    {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            $this->components->warn('Mailer non configurato ('.config('mail.default').'): manda tu il link.');

            return;
        }

        try {
            Notification::route('mail', $invite->email)->notify(new FamilyInvitation($invite, $url));
            $this->components->info("Invito mandato anche per email a {$invite->email}.");
        } catch (Throwable $e) {
            $this->components->warn("Email non partita ({$e->getMessage()}): manda tu il link.");
        }
    }
}
