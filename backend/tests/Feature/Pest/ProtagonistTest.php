<?php

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\User;
use App\Services\FamilyReadTokens;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    config(['photodaily.frontend_url' => 'https://photodaily.app']);

    $this->family = Family::factory()->create(['slug' => 'giopellino']);
    $this->admin = User::factory()->admin()->for($this->family)->create();
});

it('lets an admin set name and birthdate of the protagonist', function () {
    Sanctum::actingAs($this->admin);

    $this->patchJson('/api/family', ['protagonist_name' => '  Gio ', 'protagonist_birthdate' => '2023-11-14'])
        ->assertOk()
        ->assertJsonPath('data.protagonist', ['name' => 'Gio', 'birthdate' => '2023-11-14']);

    expect($this->family->fresh()->protagonist())->toBe(['name' => 'Gio', 'birthdate' => '2023-11-14']);
});

it('updates only the fields that are sent and can clear them', function () {
    $this->family->update(['protagonist_name' => 'Gio', 'protagonist_birthdate' => '2023-11-14']);
    Sanctum::actingAs($this->admin);

    $this->patchJson('/api/family', ['protagonist_name' => 'Giorgio'])
        ->assertJsonPath('data.protagonist', ['name' => 'Giorgio', 'birthdate' => '2023-11-14']);

    $this->patchJson('/api/family', ['protagonist_name' => '', 'protagonist_birthdate' => null])
        ->assertJsonPath('data.protagonist', null);
});

it('refuses members, future birthdates and overlong names', function () {
    Sanctum::actingAs(User::factory()->for($this->family)->create());
    $this->patchJson('/api/family', ['protagonist_name' => 'Gio'])->assertForbidden();

    Sanctum::actingAs($this->admin);
    $this->patchJson('/api/family', ['protagonist_birthdate' => now()->addDays(2)->toDateString()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.protagonist_birthdate.0', 'La data di nascita non può essere nel futuro.');
    $this->patchJson('/api/family', ['protagonist_birthdate' => '14/11/2023'])->assertUnprocessable();
    $this->patchJson('/api/family', ['protagonist_name' => str_repeat('a', 61)])->assertUnprocessable();

    expect($this->family->fresh()->protagonist())->toBeNull();
});

it('exposes the protagonist to members in /api/me', function () {
    $this->family->update(['protagonist_name' => 'Gio', 'protagonist_birthdate' => '2023-11-14']);
    Sanctum::actingAs(User::factory()->for($this->family)->create());

    $this->getJson('/api/me')->assertJsonPath('data.family.protagonist.birthdate', '2023-11-14');
});

it('shows the protagonist on /api/site only for public diaries', function (string $mode, bool $visible) {
    $this->family->update(['protagonist_name' => 'Gio', 'protagonist_birthdate' => '2023-11-14']);
    $this->family->changeAccessMode(AccessMode::from($mode), $mode === 'password' ? 'password-lunga' : null);

    $protagonist = $this->getJson('https://giopellino.photodaily.app/api/site')->assertOk()->json('data.family.protagonist');

    expect($protagonist)->toBe($visible ? ['name' => 'Gio', 'birthdate' => '2023-11-14'] : null);
})->with([
    'privato' => ['private', false],
    'con password' => ['password', false],
    'pubblico' => ['public', true],
]);

it('gives the protagonist to password diaries only after unlocking', function () {
    $this->family->update(['protagonist_name' => 'Gio', 'protagonist_birthdate' => '2023-11-14']);
    $this->family->changeAccessMode(AccessMode::Password, 'password-lunga');

    $this->getJson('/api/public/giopellino/profilo')->assertUnauthorized();

    [$token] = app(FamilyReadTokens::class)->issue($this->family->fresh());

    $this->withToken($token)->getJson('/api/public/giopellino/profilo')
        ->assertOk()
        ->assertJsonPath('data.protagonist.name', 'Gio');
});

it('never gives the profile of a private diary to guests', function () {
    $this->family->update(['protagonist_name' => 'Gio']);

    $this->getJson('/api/public/giopellino/profilo')->assertNotFound();
});
