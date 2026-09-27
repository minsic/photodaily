<?php

use App\Enums\AccessMode;
use App\Enums\Role;
use App\Models\Family;
use App\Models\Photo;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config(['photodaily.frontend_url' => 'https://photodaily.app']);

    $this->own = Family::factory()->create(['slug' => 'giopellino']);
    $this->boss = User::factory()->for($this->own)->create(['role' => Role::Admin]);
    $this->boss->forceFill(['is_super_admin' => true])->save();

    $this->other = Family::factory()->create(['slug' => 'rossi', 'name' => 'Famiglia Rossi']);
    $this->rossi = User::factory()->for($this->other)->create([
        'role' => Role::Admin,
        'email' => 'anna@rossi.it',
        'password' => Hash::make('password-di-anna'),
    ]);
    Photo::factory()->for($this->other)->count(2)->create();
});

/** Token vero dell'utente, per passare dagli stessi middleware del frontend. */
function tokenOf(User $user): string
{
    return $user->createToken('prova')->plainTextToken;
}

it('lists every diary with plan, counts and admins', function () {
    $response = $this->withToken(tokenOf($this->boss))->getJson('https://photodaily.app/api/admin/famiglie')->assertOk();

    $rossi = collect($response->json('data'))->firstWhere('slug', 'rossi');

    expect($response->json('data'))->toHaveCount(2)
        ->and($rossi['name'])->toBe('Famiglia Rossi')
        ->and($rossi['url'])->toBe('https://rossi.photodaily.app')
        ->and($rossi['plan'])->toBe('beta')
        ->and($rossi['photos_count'])->toBe(2)
        ->and($rossi['users_count'])->toBe(1)
        ->and($rossi['admins'])->toBe(['anna@rossi.it'])
        ->and($rossi['suspended_at'])->toBeNull()
        ->and($rossi)->not->toHaveKey('photos');
});

it('is invisible to everyone else and outside the main address', function () {
    $this->withToken(tokenOf($this->rossi))->getJson('https://photodaily.app/api/admin/famiglie')->assertNotFound();
    $this->withToken(tokenOf($this->boss))->getJson('https://giopellino.photodaily.app/api/admin/famiglie')->assertNotFound();
    $this->withToken(tokenOf($this->rossi))->patchJson('https://photodaily.app/api/admin/famiglie/rossi', ['plan' => 'free'])->assertNotFound();

    $this->app['auth']->forgetGuards();
    $this->withoutToken()->getJson('https://photodaily.app/api/admin/famiglie')->assertUnauthorized();
});

it('changes the plan of a diary', function () {
    $this->withToken(tokenOf($this->boss))
        ->patchJson('https://photodaily.app/api/admin/famiglie/rossi', ['plan' => 'free'])
        ->assertOk()
        ->assertJsonPath('data.plan', 'free');

    expect($this->other->fresh()->plan->slug)->toBe('free');

    $this->withToken(tokenOf($this->boss))
        ->patchJson('https://photodaily.app/api/admin/famiglie/rossi', ['plan' => 'oro'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['plan']);
});

it('lists the plans', function () {
    $this->withToken(tokenOf($this->boss))
        ->getJson('https://photodaily.app/api/admin/piani')
        ->assertOk()
        ->assertJsonFragment(['slug' => 'free', 'max_photos' => 100, 'max_storage_mb' => 500]);

    expect(Plan::query()->count())->toBeGreaterThanOrEqual(2);
});

it('suspends a diary: no login, no api, no public diary', function () {
    $this->other->changeAccessMode(AccessMode::Public);
    $open = tokenOf($this->rossi);

    $this->withToken(tokenOf($this->boss))
        ->patchJson('https://photodaily.app/api/admin/famiglie/rossi', ['sospesa' => true])
        ->assertOk();
    expect($this->other->fresh()->suspended_at)->not->toBeNull();

    $this->app['auth']->forgetGuards();
    $this->withoutToken()
        ->postJson('https://rossi.photodaily.app/api/login', ['email' => 'anna@rossi.it', 'password' => 'password-di-anna'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Questo diario è sospeso. Per informazioni scrivi a chi gestisce PhotoDaily.');
    $this->getJson('https://rossi.photodaily.app/api/public/rossi/photos')->assertNotFound();

    $this->withToken($open)->getJson('https://rossi.photodaily.app/api/me')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($open)->getJson('https://rossi.photodaily.app/api/photos')->assertForbidden();
    $this->app['auth']->forgetGuards();
    $this->withToken($open)->postJson('https://rossi.photodaily.app/api/logout')->assertNoContent();
});

it('reactivates a suspended diary', function () {
    $this->other->forceFill(['suspended_at' => now()])->save();

    $this->withToken(tokenOf($this->boss))
        ->patchJson('https://photodaily.app/api/admin/famiglie/rossi', ['sospesa' => false])
        ->assertOk()
        ->assertJsonPath('data.suspended_at', null);

    $this->app['auth']->forgetGuards();
    $this->withoutToken()
        ->postJson('https://rossi.photodaily.app/api/login', ['email' => 'anna@rossi.it', 'password' => 'password-di-anna'])
        ->assertOk();
});

it('does not let the super admin lock out their own diary', function () {
    $this->withToken(tokenOf($this->boss))
        ->patchJson('https://photodaily.app/api/admin/famiglie/giopellino', ['sospesa' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sospesa']);

    expect($this->own->fresh()->suspended_at)->toBeNull();
});

it('grants and revokes the super admin flag from the terminal', function () {
    $this->artisan('user:super-admin', ['email' => 'Anna@Rossi.it'])->assertSuccessful();
    expect($this->rossi->fresh()->is_super_admin)->toBeTrue();

    $this->artisan('user:super-admin', ['email' => 'anna@rossi.it', '--revoke' => true])->assertSuccessful();
    expect($this->rossi->fresh()->is_super_admin)->toBeFalse();

    $this->artisan('user:super-admin', ['email' => 'nessuno@example.com'])->assertFailed();
});

it('tells the frontend who is a super admin', function () {
    $this->withToken(tokenOf($this->boss))->getJson('https://photodaily.app/api/me')->assertJsonPath('data.super_admin', true);

    $this->app['auth']->forgetGuards();
    $this->withToken(tokenOf($this->rossi))->getJson('https://rossi.photodaily.app/api/me')->assertJsonPath('data.super_admin', false);
});
