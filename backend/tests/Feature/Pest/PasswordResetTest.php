<?php

use App\Models\Family;
use App\Models\User;
use App\Notifications\ResetPasswordLink;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    config(['photodaily.frontend_url' => 'https://photodaily.app']);

    $this->family = Family::factory()->create(['slug' => 'carozzi']);
    $this->user = User::factory()->for($this->family)->create(['email' => 'hello@example.com']);
});

/** Chiede il link e restituisce il token che arriva per email. */
function requestLink(string $host = 'https://carozzi.photodaily.app', string $email = 'hello@example.com'): ?string
{
    test()->postJson("{$host}/api/password/dimenticata", ['email' => $email])->assertStatus(202);

    $token = null;
    Notification::assertSentTo(test()->user, ResetPasswordLink::class, function (ResetPasswordLink $notification) use (&$token) {
        $token = $notification->token;

        return true;
    });

    return $token;
}

it('sends a link to the address of the family, in italian', function () {
    requestLink('https://photodaily.app', 'Hello@Example.com');

    Notification::assertSentTo($this->user, ResetPasswordLink::class, function (ResetPasswordLink $notification) {
        $mail = $notification->toMail($this->user);

        return str_starts_with($notification->url($this->user), 'https://carozzi.photodaily.app/password/nuova?token=')
            && str_contains($notification->url($this->user), 'email=hello%40example.com')
            && $mail->subject === 'Nuova password per PhotoDaily';
    });
});

it('answers the same whether the account exists or not, and on the address of another family', function () {
    Family::factory()->create(['slug' => 'giopellino']);

    $known = $this->postJson('https://carozzi.photodaily.app/api/password/dimenticata', ['email' => 'hello@example.com']);
    $unknown = $this->postJson('https://carozzi.photodaily.app/api/password/dimenticata', ['email' => 'nessuno@example.com']);
    $otherHost = $this->postJson('https://giopellino.photodaily.app/api/password/dimenticata', ['email' => 'hello@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toBe($known->json())
        ->and($otherHost->json())->toBe($known->json());

    // Solo la prima ha mandato qualcosa.
    Notification::assertSentToTimes($this->user, ResetPasswordLink::class, 1);
});

it('sets the new password, closes the other sessions and logs in', function () {
    $this->user->createToken('vecchio-telefono');
    $token = requestLink();

    $response = $this->postJson('https://carozzi.photodaily.app/api/password/nuova', [
        'token' => $token,
        'email' => 'hello@example.com',
        'password' => 'una-password-nuova',
        'password_confirmation' => 'una-password-nuova',
    ])->assertOk()->assertJsonPath('user.email', 'hello@example.com');

    expect(Hash::check('una-password-nuova', $this->user->fresh()->password))->toBeTrue()
        ->and($this->user->tokens()->pluck('name')->all())->toBe(['spa']);

    $this->withToken($response->json('token'))->getJson('https://carozzi.photodaily.app/api/me')->assertOk();

    // Il link vale una volta sola.
    $this->app['auth']->forgetGuards();
    $this->postJson('https://carozzi.photodaily.app/api/password/nuova', [
        'token' => $token, 'email' => 'hello@example.com',
        'password' => 'un-altra-password', 'password_confirmation' => 'un-altra-password',
    ])->assertUnprocessable()->assertJsonPath('errors.token.0', 'Il link non è valido o è scaduto: chiedine uno nuovo.');
});

it('refuses wrong, expired or foreign-host links and changes nothing', function () {
    $before = $this->user->password;
    $token = requestLink();
    $payload = ['email' => 'hello@example.com', 'password' => 'una-password-nuova', 'password_confirmation' => 'una-password-nuova'];

    $this->postJson('https://carozzi.photodaily.app/api/password/nuova', [...$payload, 'token' => 'sbagliato'])->assertUnprocessable();

    Family::factory()->create(['slug' => 'giopellino']);
    $this->postJson('https://giopellino.photodaily.app/api/password/nuova', [...$payload, 'token' => $token])->assertUnprocessable();

    $this->travel(61)->minutes();
    $this->postJson('https://carozzi.photodaily.app/api/password/nuova', [...$payload, 'token' => $token])->assertUnprocessable();

    expect($this->user->fresh()->password)->toBe($before);
});

it('asks for a strong, confirmed password', function () {
    $token = requestLink();

    $this->postJson('https://carozzi.photodaily.app/api/password/nuova', [
        'token' => $token, 'email' => 'hello@example.com', 'password' => 'corta', 'password_confirmation' => 'altra',
    ])->assertUnprocessable()->assertJsonValidationErrors('password');
});

it('limits the requests', function () {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/password/dimenticata', ['email' => "prova{$attempt}@example.com"])->assertStatus(202);
    }

    $this->postJson('/api/password/dimenticata', ['email' => 'prova6@example.com'])->assertTooManyRequests();
});
