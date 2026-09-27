<?php

use App\Models\Family;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    $this->family = Family::factory()->create(['slug' => 'carozzi']);
    $this->user = User::factory()->for($this->family)->create(['name' => 'Simone', 'email' => 'hello@example.com']);
    $this->other = User::factory()->for($this->family)->create(['name' => 'Nonna']);
    $this->token = $this->user->createToken('questo-telefono')->plainTextToken;
    $this->user->createToken('vecchio-computer');
});

it('changes only the own name', function () {
    $this->withToken($this->token)
        ->patchJson('/api/me', ['name' => '  Simone C.  ', 'email' => 'altro@example.com', 'role' => 'admin', 'family_id' => 999])
        ->assertOk()
        ->assertJsonPath('data.name', 'Simone C.')
        ->assertJsonPath('data.email', 'hello@example.com');

    expect($this->user->fresh()->name)->toBe('Simone C.')
        ->and($this->other->fresh()->name)->toBe('Nonna');

    $this->withToken($this->token)->patchJson('/api/me', ['name' => ''])->assertUnprocessable()->assertJsonPath('errors.name.0', 'Serve un nome.');
});

it('changes the password with the current one and closes the other devices only', function () {
    $this->withToken($this->token)->putJson('/api/me/password', [
        'password_attuale' => 'password',
        'password' => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertOk()->assertJsonPath('data.sessioni_chiuse', 1);

    expect(Hash::check('una-password-nuova', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->pluck('name')->all())->toBe(['questo-telefono']);

    $this->app['auth']->forgetGuards();
    $this->withToken($this->token)->getJson('/api/me')->assertOk();
});

it('refuses a wrong current password, a weak or unconfirmed new one', function () {
    $before = $this->user->password;
    $base = ['password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'];

    $this->withToken($this->token)->putJson('/api/me/password', [...$base, 'password_attuale' => 'sbagliata'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password_attuale.0', 'La password attuale non è corretta.');

    $this->withToken($this->token)->putJson('/api/me/password', ['password_attuale' => 'password', 'password' => 'corta', 'password_confirmation' => 'corta'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');

    $this->withToken($this->token)->putJson('/api/me/password', ['password_attuale' => 'password', 'password' => 'una-password-nuova', 'password_confirmation' => 'diversa-davvero'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.password.0', 'Le due password nuove non coincidono.');

    expect($this->user->fresh()->password)->toBe($before)
        ->and($this->user->tokens()->count())->toBe(2);
});

it('logs out the other devices, keeping this one', function () {
    $this->other->createToken('telefono-della-nonna');

    $this->withToken($this->token)->deleteJson('/api/me/sessioni')->assertOk()->assertJsonPath('data.sessioni_chiuse', 1);

    expect($this->user->tokens()->pluck('name')->all())->toBe(['questo-telefono'])
        ->and($this->other->tokens()->count())->toBe(1);
});

it('is only for who is logged in', function () {
    $this->patchJson('/api/me', ['name' => 'X'])->assertUnauthorized();
    $this->putJson('/api/me/password', [])->assertUnauthorized();
    $this->deleteJson('/api/me/sessioni')->assertUnauthorized();
});
