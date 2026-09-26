<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('sets a new password asked in hidden prompts and closes the open sessions', function () {
    $user = User::factory()->create(['email' => 'hello@example.com']);
    $user->createToken('telefono');
    $user->createToken('computer');

    $this->artisan('user:set-password', ['email' => 'Hello@Example.com'])
        ->expectsQuestion('Nuova password', 'una-password-nuova')
        ->expectsQuestion('Ripetila', 'una-password-nuova')
        ->expectsOutputToContain('Sessioni chiuse: 2')
        ->assertSuccessful();

    expect(Hash::check('una-password-nuova', $user->fresh()->password))->toBeTrue()
        ->and($user->tokens()->count())->toBe(0);

    $this->postJson('/api/login', ['email' => 'hello@example.com', 'password' => 'una-password-nuova'])->assertOk();
});

it('changes nothing when the two passwords differ, are too short or the account does not exist', function () {
    $user = User::factory()->create(['email' => 'hello@example.com']);
    $before = $user->password;

    $this->artisan('user:set-password', ['email' => 'hello@example.com'])
        ->expectsQuestion('Nuova password', 'una-password-nuova')
        ->expectsQuestion('Ripetila', 'un-altra-password')
        ->expectsOutputToContain('non coincidono')
        ->assertFailed();

    $this->artisan('user:set-password', ['email' => 'hello@example.com'])
        ->expectsQuestion('Nuova password', 'corta')
        ->expectsQuestion('Ripetila', 'corta')
        ->assertFailed();

    $this->artisan('user:set-password', ['email' => 'nessuno@example.com'])
        ->expectsOutputToContain('Nessun account')
        ->assertFailed();

    expect($user->fresh()->password)->toBe($before);
});
