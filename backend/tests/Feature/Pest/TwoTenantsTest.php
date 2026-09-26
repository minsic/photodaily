<?php

use App\Enums\AccessMode;
use App\Enums\Role;
use App\Models\Family;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\User;
use App\Services\FamilyReadTokens;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Due famiglie vere, come giopellino e carozzi in produzione: foto caricate
 * dall'API (quindi su R2 con i loro percorsi), token veri, ogni endpoint di
 * lettura chiamato con il token di una famiglia. Di quella dell'altra non
 * deve comparire niente: né foto, né date, né conteggi, né inviti.
 */
beforeEach(function () {
    Storage::fake('r2');
    config(['photodaily.frontend_url' => 'https://photodaily.app']);
    $this->travelTo(new DateTimeImmutable('2026-03-20 12:00:00', new DateTimeZone('Europe/Rome')));

    $this->tenants = [];

    foreach (['giopellino' => ['2026-03-01', '2026-03-02', '2026-03-03'], 'carozzi' => ['2026-03-10', '2026-03-11']] as $slug => $days) {
        $family = Family::factory()->create(['slug' => $slug, 'protagonist_birthdate' => '2026-03-01']);
        $admin = User::factory()->for($family)->create(['role' => Role::Admin]);
        $token = $admin->createToken('prova')->plainTextToken;
        $invite = Invite::factory()->for($family)->create(['email' => "invitata@{$slug}.it", 'invited_by' => $admin->id]);

        foreach ($days as $day) {
            $this->withToken($token)
                ->postJson("https://{$slug}.photodaily.app/api/photos", [
                    'image' => UploadedFile::fake()->image("{$slug}-{$day}.jpg", 800, 600),
                    'data' => $day,
                    'data_speciale' => true,
                ])->assertCreated();
        }

        $this->app['auth']->forgetGuards();

        $this->tenants[$slug] = [
            'family' => $family,
            'token' => $token,
            'invite' => $invite,
            'photos' => $family->photos()->pluck('ulid')->all(),
            'days' => $days,
        ];
    }
});

/**
 * Le date dei giorni con foto che una risposta mostra: quelle delle foto
 * (data.*.data, giorni.*.data), non i giorni vuoti del calendario, che
 * possono coincidere per caso con quelli dell'altra famiglia.
 *
 * @return list<string>
 */
function photoDaysIn(array $json): array
{
    $rows = $json['data']['giorni'] ?? (array_is_list($json['data'] ?? null) ? $json['data'] : []);

    return array_values(array_filter(array_map(fn ($row) => is_array($row) ? ($row['data'] ?? null) : null, $rows)));
}

it('keeps every endpoint of each family blind to the other one', function (string $viewer, string $other, string $hostKind) {
    $me = $this->tenants[$viewer];
    $them = $this->tenants[$other];
    $host = $hostKind === 'proprio' ? "https://{$viewer}.photodaily.app" : 'https://photodaily.app';

    $endpoints = [
        '/api/me',
        '/api/photos?per_page=100',
        '/api/photos/anni',
        '/api/photos/calendario?anno=2026',
        '/api/photos/mese?anno=2026&mese=3',
        '/api/photos/sequenza',
        '/api/family/quota',
        '/api/invites',
    ];

    foreach ($endpoints as $endpoint) {
        $response = $this->withToken($me['token'])->getJson($host.$endpoint)->assertOk();
        $body = $response->getContent();

        // Id delle foto, email dell'invito, slug e prefisso R2 (dentro gli URL firmati) dell'altra.
        $foreign = [...$them['photos'], $them['invite']->email, $them['family']->slug, "families/{$them['family']->id}/"];

        foreach ($foreign as $marker) {
            expect(str_contains($body, $marker))->toBeFalse("{$endpoint} di {$viewer} mostra {$marker} di {$other}");
        }

        expect(array_diff(photoDaysIn($response->json()), $me['days']))->toBe([], "{$endpoint} di {$viewer} mostra giorni di {$other}");

        $this->app['auth']->forgetGuards();
    }

    // I conteggi sono i suoi.
    $this->withToken($me['token'])->getJson("{$host}/api/family/quota")->assertJsonPath('data.photos', count($me['photos']));
    $this->withToken($me['token'])->getJson("{$host}/api/photos/anni")->assertJsonPath('data.0.foto', count($me['photos']));

    // Le foto dell'altra non si aprono, non si modificano, non si cancellano.
    foreach ($them['photos'] as $id) {
        foreach (['GET', 'PATCH', 'DELETE'] as $method) {
            $this->withToken($me['token'])->json($method, "{$host}/api/photos/{$id}", ['didascalia' => 'x'])->assertNotFound();
        }
    }

    $this->withToken($me['token'])->deleteJson("{$host}/api/invites/{$them['invite']->ulid}")->assertNotFound();
    expect(Photo::whereIn('ulid', $them['photos'])->count())->toBe(count($them['photos']));
})->with([
    'giopellino guarda carozzi, dal suo indirizzo' => ['giopellino', 'carozzi', 'proprio'],
    'carozzi guarda giopellino, dal suo indirizzo' => ['carozzi', 'giopellino', 'proprio'],
    'giopellino dall\'indirizzo principale' => ['giopellino', 'carozzi', 'principale'],
    'carozzi dall\'indirizzo principale' => ['carozzi', 'giopellino', 'principale'],
]);

it('refuses the token of one family on the address of the other', function () {
    $this->withToken($this->tenants['giopellino']['token'])->getJson('https://carozzi.photodaily.app/api/photos')->assertNotFound();
    $this->app['auth']->forgetGuards();
    $this->withToken($this->tenants['carozzi']['token'])->getJson('https://giopellino.photodaily.app/api/me')->assertNotFound();
});

it('stores every file of a family under its own prefix on R2', function () {
    foreach ($this->tenants as $tenant) {
        $prefix = "families/{$tenant['family']->id}/";
        $paths = Photo::where('family_id', $tenant['family']->id)->get(['image_path', 'thumbnail_path', 'medium_path'])
            ->flatMap(fn (Photo $photo) => array_filter([$photo->image_path, $photo->thumbnail_path, $photo->medium_path]));

        expect($paths)->not->toBeEmpty()
            ->and($paths->every(fn (string $path) => str_starts_with($path, $prefix)))->toBeTrue();
    }

    $all = collect(Storage::disk('r2')->allFiles());
    expect($all->every(fn (string $path) => preg_match('~^families/\d+/~', $path) === 1))->toBeTrue();
});

it('opens the public diary of one family with its read token only', function () {
    $gio = $this->tenants['giopellino']['family'];
    $gio->changeAccessMode(AccessMode::Password, 'password-di-gio');
    $this->tenants['carozzi']['family']->changeAccessMode(AccessMode::Password, 'password-di-carozzi');
    [$readToken] = app(FamilyReadTokens::class)->issue($gio->fresh());

    foreach (['photos', 'photos/anni', 'photos/sequenza', 'photos/mese?anno=2026&mese=3', 'photos/calendario?anno=2026'] as $endpoint) {
        $this->withToken($readToken)->getJson("/api/public/giopellino/{$endpoint}")->assertOk();
        $this->withToken($readToken)->getJson("/api/public/carozzi/{$endpoint}")->assertUnauthorized();
    }
});

it('never sets a cookie shared between subdomains', function () {
    $response = $this->postJson('https://carozzi.photodaily.app/api/login', [
        'email' => User::where('family_id', $this->tenants['carozzi']['family']->id)->value('email'),
        'password' => 'password',
    ])->assertOk();

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getDomain())->toBeNull();
    }

    expect(config('session.domain'))->toBeNull();
});

it('answers not found for a subdomain without a family, so the app shows "Diario non trovato"', function () {
    $this->getJson('https://inesistente.photodaily.app/api/site')->assertNotFound();
    $this->getJson('https://carozzi.photodaily.app/api/site')->assertOk()->assertJsonPath('data.family.slug', 'carozzi');
});
