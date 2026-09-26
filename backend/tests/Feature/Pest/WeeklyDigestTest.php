<?php

use App\Models\Family;
use App\Models\Photo;
use App\Models\PhotoHeart;
use App\Models\User;
use App\Notifications\WeeklyDigestMail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    Notification::fake();
    config(['photodaily.frontend_url' => 'https://photodaily.app']);

    // Domenica 27 settembre 2026, 19:05 a Roma (17:05 UTC).
    $this->travelTo(new DateTimeImmutable('2026-09-27 17:05:00', new DateTimeZone('UTC')));

    $this->family = Family::factory()->create(['slug' => 'giopellino', 'timezone' => 'Europe/Rome', 'protagonist_name' => 'Gio', 'protagonist_birthdate' => '2023-11-14']);
    $this->mamma = User::factory()->for($this->family)->create(['name' => 'Mamma']);
    $this->papa = User::factory()->for($this->family)->create(['name' => 'Papà']);

    foreach (['2026-09-21', '2026-09-23', '2026-09-27'] as $day) {
        Photo::factory()->for($this->family)->create(['data' => $day]);
    }

    Photo::factory()->for($this->family)->draft()->create(['data' => '2026-09-22']);
    Photo::factory()->for($this->family)->create(['data' => '2026-09-20']);
});

it('sends the week to every member on sunday evening, once', function () {
    PhotoHeart::create(['photo_id' => Photo::where('data', '2026-09-23')->value('id'), 'user_id' => $this->papa->id]);

    $this->artisan('photos:send-weekly-digest')->expectsOutputToContain('Riepiloghi mandati: 2')->assertSuccessful();

    Notification::assertSentTo([$this->mamma, $this->papa], WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
        return array_column($mail->digest['giorni'], 'data') === ['2026-09-21', '2026-09-23', '2026-09-27']
            && $mail->digest['pieni'] === 3
            && $mail->digest['totali'] === 7
            && $mail->digest['mancanti'] === ['2026-09-22', '2026-09-24', '2026-09-25', '2026-09-26']
            && $mail->digest['cuori'] === 1;
    });

    // Un'ora dopo non riparte.
    $this->travel(1)->hours();
    $this->artisan('photos:send-weekly-digest')->expectsOutputToContain('Riepiloghi mandati: 0');
    Notification::assertSentTimes(WeeklyDigestMail::class, 2);
});

it('waits for 19:00 on sunday in the family timezone', function () {
    // Domenica 18:30 a Roma: ancora presto.
    $this->travelTo(new DateTimeImmutable('2026-09-27 16:30:00', new DateTimeZone('UTC')));
    $this->artisan('photos:send-weekly-digest');

    // Sabato sera: non è domenica.
    $this->travelTo(new DateTimeImmutable('2026-09-26 19:30:00', new DateTimeZone('Europe/Rome')));
    $this->artisan('photos:send-weekly-digest');

    Notification::assertNothingSent();
});

it('skips who turned it off and weeks without photos', function () {
    Sanctum::actingAs($this->papa);
    $this->patchJson('/api/me/promemoria', ['riepilogo' => false])->assertOk()->assertJsonPath('data.promemoria.riepilogo', false);

    $empty = Family::factory()->create(['timezone' => 'Europe/Rome']);
    $lonely = User::factory()->for($empty)->create();

    $this->artisan('photos:send-weekly-digest')->assertSuccessful();

    Notification::assertSentTo($this->mamma, WeeklyDigestMail::class);
    Notification::assertNotSentTo([$this->papa, $lonely], WeeklyDigestMail::class);
});

it('never puts photos of another family in the email', function () {
    $other = Family::factory()->create(['timezone' => 'Europe/Rome']);
    $foreign = Photo::factory()->for($other)->create(['data' => '2026-09-24']);
    User::factory()->for($other)->create();

    $this->artisan('photos:send-weekly-digest');

    Notification::assertSentTo($this->mamma, WeeklyDigestMail::class, fn (WeeklyDigestMail $mail) => ! in_array($foreign->ulid, array_column($mail->digest['giorni'], 'id'), true)
        && ! in_array('2026-09-24', array_column($mail->digest['giorni'], 'data'), true));
});

it('renders an email with thumbnails, missing days and the way to turn it off', function () {
    $this->artisan('photos:send-weekly-digest');

    Notification::assertSentTo($this->mamma, WeeklyDigestMail::class, function (WeeklyDigestMail $mail) {
        $message = $mail->toMail($this->mamma);
        $html = (string) $message->render();

        return $message->subject === 'La settimana di Gio su PhotoDaily'
            && str_contains($html, '3 foto su 7 giorni')
            && str_contains($html, 'giovedì 24')
            && str_contains($html, 'https://giopellino.photodaily.app/mese/2026/9')
            && str_contains($html, 'https://giopellino.photodaily.app/profilo')
            && substr_count($html, '<img src=') >= 3;
    });
});
