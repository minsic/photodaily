<?php

use App\Enums\AccessMode;
use App\Enums\Role;
use App\Models\Family;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\FamilyInvitation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    config(['photodaily.frontend_url' => 'https://photodaily.app', 'mail.default' => 'array']);
});

it('creates the family and an admin invite, with no password in the terminal', function () {
    $this->artisan('family:create', ['slug' => 'carozzi', 'name' => 'Famiglia Carozzi', 'email' => 'Admin@Carozzi.it', '--timezone' => 'Europe/London'])
        ->expectsOutputToContain('https://carozzi.photodaily.app/invite/')
        ->expectsOutputToContain('Mailer non configurato')
        ->doesntExpectOutputToContain('Password')
        ->assertSuccessful();

    $family = Family::where('slug', 'carozzi')->sole();
    $invite = $family->invites()->sole();

    expect($family->name)->toBe('Famiglia Carozzi')
        ->and($family->access_mode)->toBe(AccessMode::Private)
        ->and($family->timezone)->toBe('Europe/London')
        ->and($family->plan->slug)->toBe(config('photodaily.default_plan'))
        ->and($family->users()->count())->toBe(0)
        ->and($invite->email)->toBe('admin@carozzi.it')
        ->and($invite->role)->toBe(Role::Admin)
        ->and($invite->invited_by)->toBeNull();
});

it('lets the invited admin choose the password and enter as admin', function () {
    Artisan::call('family:create', ['slug' => 'carozzi', 'name' => 'Carozzi', 'email' => 'admin@carozzi.it']);
    preg_match('~/invite/([A-Za-z0-9]{64})~', Artisan::output(), $match);

    $this->postJson("https://carozzi.photodaily.app/api/invites/{$match[1]}/accept", [
        'name' => 'Simone', 'password' => 'una-password-lunga', 'password_confirmation' => 'una-password-lunga',
    ])->assertCreated()->assertJsonPath('user.role', 'admin');

    expect(User::where('email', 'admin@carozzi.it')->sole()->role)->toBe(Role::Admin);
});

it('sends the invite by email when a real mailer is configured', function () {
    Notification::fake();
    config(['mail.default' => 'smtp']);

    $this->artisan('family:create', ['slug' => 'carozzi', 'name' => 'Carozzi', 'email' => 'admin@carozzi.it', '--access-mode' => 'public'])
        ->expectsOutputToContain('mandato anche per email')
        ->assertSuccessful();

    Notification::assertSentOnDemand(
        FamilyInvitation::class,
        fn (FamilyInvitation $notification, array $channels, object $notifiable) => $notifiable->routes['mail'] === 'admin@carozzi.it'
            && str_contains($notification->toMail($notifiable)->subject, 'è pronto'),
    );
    expect(Family::where('slug', 'carozzi')->value('access_mode'))->toBe(AccessMode::Public);
});

it('rejects bad slugs, reserved ones, taken emails and wrong options', function (array $arguments, string $error) {
    Family::factory()->create(['slug' => 'giopellino']);
    User::factory()->create(['email' => 'preso@example.com']);
    Invite::factory()->create(['email' => 'invitato@example.com']);

    $this->artisan('family:create', ['name' => 'Prova', 'slug' => 'prova', 'email' => 'nuova@example.com', ...$arguments])
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(Family::where('name', 'Prova')->exists())->toBeFalse();
})->with([
    'troppo corto' => [['slug' => 'ab'], 'deve avere 3-30 caratteri'],
    'troppo lungo' => [['slug' => str_repeat('a', 31)], 'deve avere 3-30 caratteri'],
    'maiuscole' => [['slug' => 'Carozzi'], 'deve avere 3-30 caratteri'],
    'trattino al bordo' => [['slug' => 'carozzi-'], 'deve avere 3-30 caratteri'],
    'riservato' => [['slug' => 'staging'], 'Questo indirizzo è riservato'],
    'già usato' => [['slug' => 'giopellino'], 'Questo indirizzo è già preso'],
    'email con account' => [['email' => 'preso@example.com'], 'già un account o un invito'],
    'email già invitata' => [['email' => 'invitato@example.com'], 'già un account o un invito'],
    'modalità password' => [['--access-mode' => 'password'], 'private o public'],
    'fuso inventato' => [['--timezone' => 'Europe/Atlantide'], 'Fuso orario non riconosciuto'],
    'piano inesistente' => [['--plan' => 'oro'], 'plan'],
]);

it('lists families with plan, photos, storage and last upload', function () {
    $family = Family::factory()->create(['slug' => 'carozzi', 'name' => 'Famiglia Carozzi']);
    Family::factory()->create(['slug' => 'vuota', 'name' => 'Famiglia Vuota']);
    $this->travelTo(new DateTimeImmutable('2026-09-20 10:15:00', new DateTimeZone('UTC')));
    Photo::factory()->for($family)->count(3)->create(['size_bytes' => 1024 * 1024, 'medium_bytes' => 0, 'thumbnail_bytes' => 0]);
    $family->refreshStorageUsage();

    $this->artisan('family:list')
        ->expectsTable(
            ['Slug', 'Nome', 'Piano', 'Accesso', 'Foto', 'Spazio', 'Ultimo caricamento'],
            [
                ['carozzi', 'Famiglia Carozzi', $family->plan->slug, 'private', 3, '3,0 MB', '20/09/2026 12:15'],
                ['vuota', 'Famiglia Vuota', $family->plan->slug, 'private', 0, '0,0 MB', '-'],
            ],
        )
        ->assertSuccessful();
});
