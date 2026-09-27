<?php

use App\Models\Family;
use App\Models\User;
use App\Notifications\ConfirmEmailChange;
use App\Notifications\EmailChangeRequested;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['photodaily.frontend_url' => 'https://photodaily.app']);

    $this->family = Family::factory()->create(['slug' => 'carozzi']);
    $this->user = User::factory()->for($this->family)->create(['email' => 'vecchia@example.com']);
    $this->token = $this->user->createToken('telefono')->plainTextToken;
});

/** Chiede il cambio e restituisce il token mandato al nuovo indirizzo. */
function askEmailChange(string $email = 'Nuova@Example.com'): string
{
    test()->withToken(test()->token)
        ->postJson('https://carozzi.photodaily.app/api/me/email', ['email' => $email, 'password_attuale' => 'password'])
        ->assertOk()
        ->assertJsonPath('data.email', 'vecchia@example.com')
        ->assertJsonPath('data.email_in_attesa', mb_strtolower($email));

    $token = null;
    Notification::assertSentOnDemand(ConfirmEmailChange::class, function (ConfirmEmailChange $notification, array $channels, object $notifiable) use (&$token, $email) {
        $token = $notification->token;

        return $notifiable->routes['mail'] === mb_strtolower($email);
    });
    app('auth')->forgetGuards();

    return $token;
}

it('sends the confirmation to the new address and a warning to the old one, and changes nothing yet', function () {
    askEmailChange();

    Notification::assertSentOnDemand(ConfirmEmailChange::class, fn (ConfirmEmailChange $notification) => str_starts_with($notification->url(), 'https://carozzi.photodaily.app/email/conferma?token='));
    Notification::assertSentTo($this->user, EmailChangeRequested::class, fn (EmailChangeRequested $notification) => $notification->newEmail === 'nuova@example.com');

    expect($this->user->fresh()->email)->toBe('vecchia@example.com');
    $this->postJson('/api/login', ['email' => 'vecchia@example.com', 'password' => 'password'])->assertOk();
});

it('switches the email when the link is opened, even without being logged in', function () {
    DB::table('password_reset_tokens')->insert(['email' => 'vecchia@example.com', 'token' => 'x', 'created_at' => now()]);
    $token = askEmailChange();

    $this->postJson('https://carozzi.photodaily.app/api/email/conferma', ['token' => $token])
        ->assertOk()
        ->assertJsonPath('data.email', 'nuova@example.com');

    $user = $this->user->fresh();
    expect($user->email)->toBe('nuova@example.com')
        ->and($user->pending_email)->toBeNull()
        ->and(DB::table('password_reset_tokens')->count())->toBe(0);

    $this->postJson('/api/login', ['email' => 'nuova@example.com', 'password' => 'password'])->assertOk();
    $this->postJson('/api/login', ['email' => 'vecchia@example.com', 'password' => 'password'])->assertUnprocessable();

    // Il link vale una volta sola; le sessioni aperte restano.
    $this->postJson('https://carozzi.photodaily.app/api/email/conferma', ['token' => $token])->assertUnprocessable();
    $this->withToken($this->token)->getJson('/api/me')->assertOk()->assertJsonPath('data.email', 'nuova@example.com');
});

it('refuses expired links, links on the address of another family and emails taken meanwhile', function () {
    Family::factory()->create(['slug' => 'giopellino']);
    $token = askEmailChange();

    $this->postJson('https://giopellino.photodaily.app/api/email/conferma', ['token' => $token])->assertUnprocessable();

    User::factory()->create(['email' => 'nuova@example.com']);
    $this->postJson('https://carozzi.photodaily.app/api/email/conferma', ['token' => $token])
        ->assertUnprocessable()
        ->assertJsonPath('errors.token.0', 'Nel frattempo questa email è stata usata da un altro account.');

    $this->travel(25)->hours();
    $this->postJson('https://carozzi.photodaily.app/api/email/conferma', ['token' => $token])
        ->assertUnprocessable()
        ->assertJsonPath('errors.token.0', 'Il link non è valido o è scaduto: chiedi di nuovo il cambio dalle impostazioni.');

    expect($this->user->fresh()->email)->toBe('vecchia@example.com');
});

it('asks for the current password and a free, different address', function () {
    User::factory()->create(['email' => 'presa@example.com']);

    $this->withToken($this->token)->postJson('/api/me/email', ['email' => 'nuova@example.com', 'password_attuale' => 'sbagliata'])
        ->assertUnprocessable()->assertJsonPath('errors.password_attuale.0', 'La password attuale non è corretta.');
    $this->withToken($this->token)->postJson('/api/me/email', ['email' => 'presa@example.com', 'password_attuale' => 'password'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'Questa email è già usata da un altro account.');
    $this->withToken($this->token)->postJson('/api/me/email', ['email' => 'VECCHIA@example.com', 'password_attuale' => 'password'])
        ->assertUnprocessable()->assertJsonPath('errors.email.0', 'È già la tua email.');

    Notification::assertNothingSent();
});

it('lets the request be cancelled, and a cancelled link no longer works', function () {
    $token = askEmailChange();

    $this->withToken($this->token)->deleteJson('/api/me/email')->assertOk()->assertJsonPath('data.email_in_attesa', null);
    $this->app['auth']->forgetGuards();

    $this->postJson('https://carozzi.photodaily.app/api/email/conferma', ['token' => $token])->assertUnprocessable();
    expect($this->user->fresh()->email)->toBe('vecchia@example.com');
});

it('never exposes the confirmation token', function () {
    askEmailChange();

    $this->withToken($this->token)->getJson('/api/me')->assertJsonMissingPath('data.pending_email_token');
    expect(array_key_exists('pending_email_token', $this->user->fresh()->toArray()))->toBeFalse();
});
