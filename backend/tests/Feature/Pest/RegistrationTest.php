<?php

use App\Enums\Role;
use App\Models\Family;
use App\Models\User;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['photodaily.frontend_url' => 'https://photodaily.app']);
});

/** @return array<string, mixed> */
function signup(array $overrides = []): array
{
    return [
        'family_name' => 'Famiglia Rossi',
        'slug' => 'rossi',
        'name' => 'Anna Rossi',
        'email' => 'Anna@Example.com',
        'password' => 'una-password-lunga',
        'password_confirmation' => 'una-password-lunga',
        'terms' => true,
        'timezone' => 'Europe/Lisbon',
        ...$overrides,
    ];
}

/** Codice monouso preso dal link restituito dalla registrazione. */
function handoffCode(TestResponse $response): string
{
    return explode('#', $response->json('data.handoff_url'), 2)[1];
}

it('creates a private diary on the free plan with its first admin', function () {
    $response = $this->postJson('https://photodaily.app/api/registrati', signup());

    $response->assertCreated()
        ->assertJsonPath('data.url', 'https://rossi.photodaily.app')
        ->assertJsonMissingPath('token');
    expect($response->json('data.handoff_url'))->toStartWith('https://rossi.photodaily.app/entra#');

    $family = Family::query()->where('slug', 'rossi')->firstOrFail();
    $user = User::query()->where('email', 'anna@example.com')->firstOrFail();

    expect($family->name)->toBe('Famiglia Rossi')
        ->and($family->access_mode->value)->toBe('private')
        ->and($family->timezone)->toBe('Europe/Lisbon')
        ->and($family->plan->slug)->toBe('free')
        ->and($family->plan->max_photos)->toBe(100)
        ->and($family->plan->max_storage_mb)->toBe(500)
        ->and($user->family_id)->toBe($family->id)
        ->and($user->role)->toBe(Role::Admin)
        ->and($user->tokens()->count())->toBe(0);
});

it('opens the session on the new subdomain with the code, only once', function () {
    $code = handoffCode($this->postJson('https://photodaily.app/api/registrati', signup()));

    $this->postJson('https://rossi.photodaily.app/api/entra', ['code' => $code])
        ->assertOk()
        ->assertJsonPath('user.email', 'anna@example.com')
        ->assertJsonPath('user.role', 'admin')
        ->assertJsonStructure(['token']);

    $this->postJson('https://rossi.photodaily.app/api/entra', ['code' => $code])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['code']);
});

it('refuses the code on another family, and burns it', function () {
    Family::factory()->create(['slug' => 'bianchi']);
    $code = handoffCode($this->postJson('https://photodaily.app/api/registrati', signup()));

    $this->postJson('https://bianchi.photodaily.app/api/entra', ['code' => $code])->assertUnprocessable();
    $this->postJson('https://rossi.photodaily.app/api/entra', ['code' => $code])->assertUnprocessable();
});

it('lets the code expire after five minutes', function () {
    $code = handoffCode($this->postJson('https://photodaily.app/api/registrati', signup()));

    $this->travel(6)->minutes();

    $this->postJson('https://rossi.photodaily.app/api/entra', ['code' => $code])->assertUnprocessable();
});

it('accepts sign ups only on the main address', function () {
    Family::factory()->create(['slug' => 'bianchi']);

    $this->postJson('https://bianchi.photodaily.app/api/registrati', signup())->assertNotFound();

    expect(Family::query()->where('slug', 'rossi')->exists())->toBeFalse();
});

it('validates the form', function (array $overrides, string $field, string $message) {
    Family::factory()->create(['slug' => 'bianchi']);
    User::factory()->create(['email' => 'preso@example.com']);

    $response = $this->postJson('https://photodaily.app/api/registrati', signup($overrides));

    $response->assertUnprocessable()->assertJsonValidationErrors([$field]);
    expect($response->json("errors.{$field}.0"))->toContain($message);
})->with([
    'indirizzo preso' => [['slug' => 'bianchi'], 'slug', 'già preso'],
    'indirizzo riservato' => [['slug' => 'admin'], 'slug', 'riservato'],
    'indirizzo non valido' => [['slug' => 'ro ssi'], 'slug', '3-30 caratteri'],
    'email con account' => [['email' => 'Preso@example.com'], 'email', 'già un account'],
    'termini' => [['terms' => false], 'terms', 'accettare termini'],
    'password diversa' => [['password_confirmation' => 'altra-password-lunga'], 'password', ''],
    'fuso inventato' => [['timezone' => 'Europe/Atlantide'], 'timezone', ''],
]);

it('accepts an upper case address and stores it in lower case', function () {
    $this->postJson('https://photodaily.app/api/registrati', signup(['slug' => ' Rossi ']))->assertCreated();

    expect(Family::query()->where('slug', 'rossi')->exists())->toBeTrue();
});

it('limits new diaries per ip, not attempts with errors', function () {
    foreach (range(1, 5) as $i) {
        $this->postJson('https://photodaily.app/api/registrati', signup(['slug' => 'bad slug']))->assertUnprocessable();
        $this->postJson('https://photodaily.app/api/registrati', signup(['slug' => "rossi{$i}", 'email' => "anna{$i}@example.com"]))->assertCreated();
        // Oltre il limite al minuto, che qui non interessa.
        $this->travel(2)->minutes();
    }

    $this->postJson('https://photodaily.app/api/registrati', signup(['slug' => 'rossi6', 'email' => 'anna6@example.com']))
        ->assertTooManyRequests();
});

it('says welcome only after a sign up', function () {
    $code = handoffCode($this->postJson('https://photodaily.app/api/registrati', signup()));

    $this->postJson('https://rossi.photodaily.app/api/entra', ['code' => $code])->assertJsonPath('benvenuto', true);
});

it('takes a logged in user from the main address to their own diary', function () {
    $family = Family::factory()->create(['slug' => 'carozzi']);
    $user = User::factory()->for($family)->create();
    $mainToken = $user->createToken('photodaily.app')->plainTextToken;

    $response = $this->withToken($mainToken)->postJson('https://photodaily.app/api/me/al-diario')->assertOk();
    expect($response->json('data.handoff_url'))->toStartWith('https://carozzi.photodaily.app/entra#');

    $this->app['auth']->forgetGuards();
    $this->withoutToken()
        ->postJson('https://carozzi.photodaily.app/api/entra', ['code' => handoffCode($response)])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('benvenuto', false);
});

it('offers the jump to the diary only on the main address', function () {
    $family = Family::factory()->create(['slug' => 'carozzi']);
    $user = User::factory()->for($family)->create();

    $this->withToken($user->createToken('prova')->plainTextToken)
        ->postJson('https://carozzi.photodaily.app/api/me/al-diario')
        ->assertNotFound();
});

it('does not open a suspended diary with a code', function () {
    $family = Family::factory()->create(['slug' => 'carozzi']);
    $user = User::factory()->for($family)->create();
    $response = $this->withToken($user->createToken('prova')->plainTextToken)->postJson('https://photodaily.app/api/me/al-diario');

    $family->forceFill(['suspended_at' => now()])->save();

    $this->app['auth']->forgetGuards();
    $this->withoutToken()
        ->postJson('https://carozzi.photodaily.app/api/entra', ['code' => handoffCode($response)])
        ->assertUnprocessable();
});

it('closes the linked session on photodaily.app when leaving the diary, and the other way round', function () {
    $family = Family::factory()->create(['slug' => 'carozzi']);
    $user = User::factory()->for($family)->create();
    $phone = $user->createToken('telefono')->plainTextToken;

    /** Entra su photodaily.app, passa al diario e restituisce i due token. */
    $enter = function () use ($user): array {
        $main = $user->createToken('photodaily.app')->plainTextToken;
        $jump = $this->withToken($main)->postJson('https://photodaily.app/api/me/al-diario');
        $this->app['auth']->forgetGuards();
        $diary = $this->withoutToken()->postJson('https://carozzi.photodaily.app/api/entra', ['code' => handoffCode($jump)])->json('token');
        $this->app['auth']->forgetGuards();

        return [$main, $diary];
    };

    [$main, $diary] = $enter();
    $this->withToken($diary)->postJson('https://carozzi.photodaily.app/api/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($main)->getJson('https://photodaily.app/api/me')->assertUnauthorized();

    [$main, $diary] = $enter();
    $this->withToken($main)->postJson('https://photodaily.app/api/logout')->assertNoContent();
    $this->app['auth']->forgetGuards();
    $this->withToken($diary)->getJson('https://carozzi.photodaily.app/api/me')->assertUnauthorized();

    // Le sessioni degli altri dispositivi restano aperte.
    $this->app['auth']->forgetGuards();
    $this->withToken($phone)->getJson('https://carozzi.photodaily.app/api/me')->assertOk();
});
