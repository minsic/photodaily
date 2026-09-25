<?php

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Notifications\DailyReminder;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Minishlink\WebPush\MessageSentReport;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\ReportHandler;
use NotificationChannels\WebPush\WebPushMessage;

beforeEach(function () {
    $this->family = Family::factory()->create(['protagonist_name' => 'Gio', 'timezone' => 'Europe/Rome']);
    $this->user = User::factory()->for($this->family)->create([
        'reminder_enabled' => true,
        'reminder_time' => '20:30:00',
    ]);
    $this->user->updatePushSubscription('https://push.example.com/abc', 'chiave', 'segreto', 'aes128gcm');
});

/** Porta l'orologio a quell'ora nel fuso di Roma. */
function atRomeTime(string $datetime): void
{
    test()->travelTo(new DateTimeImmutable($datetime, new DateTimeZone('Europe/Rome')));
}

function sendReminders(): void
{
    test()->artisan('photos:send-reminders')->assertSuccessful();
}

it('waits for the chosen time in the family timezone', function () {
    Notification::fake();

    atRomeTime('2026-03-14 20:29');
    sendReminders();
    Notification::assertNothingSent();

    atRomeTime('2026-03-14 20:31');
    sendReminders();
    Notification::assertSentTo($this->user, DailyReminder::class, fn (DailyReminder $n) => $n->day === '2026-03-14');
});

it('sends a single reminder per day and starts again the next day', function () {
    Notification::fake();

    atRomeTime('2026-03-14 20:45');
    sendReminders();
    atRomeTime('2026-03-14 21:00');
    sendReminders();
    atRomeTime('2026-03-14 23:45');
    sendReminders();
    Notification::assertSentToTimes($this->user, DailyReminder::class, 1);

    atRomeTime('2026-03-15 20:31');
    sendReminders();
    Notification::assertSentToTimes($this->user, DailyReminder::class, 2);
});

it('stays quiet when anyone in the family has already published today', function () {
    Notification::fake();
    Photo::factory()->for($this->family)->create(['data' => '2026-03-14', 'uploaded_by' => User::factory()->for($this->family)->create()->id]);

    atRomeTime('2026-03-14 21:00');
    sendReminders();

    Notification::assertNothingSent();
});

it('does not count drafts or photos of other days and families', function () {
    Notification::fake();
    Photo::factory()->for($this->family)->draft()->create(['data' => '2026-03-14']);
    Photo::factory()->for($this->family)->create(['data' => '2026-03-13']);
    Photo::factory()->create(['data' => '2026-03-14']);

    atRomeTime('2026-03-14 21:00');
    sendReminders();

    Notification::assertSentTo($this->user, DailyReminder::class);
});

it('skips users without reminder or without a subscribed device', function () {
    Notification::fake();
    $this->user->forceFill(['reminder_enabled' => false])->save();
    User::factory()->for($this->family)->create(['reminder_enabled' => true]);

    atRomeTime('2026-03-14 21:00');
    sendReminders();

    Notification::assertNothingSent();
});

it('uses the time of the family, wherever the server is', function () {
    Notification::fake();
    $this->family->update(['timezone' => 'America/New_York']);

    // 21:00 a Roma sono le 16:00 a New York.
    atRomeTime('2026-03-14 21:00');
    sendReminders();
    Notification::assertNothingSent();

    // 02:31 a Roma del 15 sono le 21:31 del 14 a New York.
    atRomeTime('2026-03-15 02:31');
    sendReminders();
    Notification::assertSentTo($this->user, DailyReminder::class, fn (DailyReminder $n) => $n->day === '2026-03-14');
});

it('builds a warm message that opens the upload for today', function () {
    $payload = (new DailyReminder($this->family, '2026-03-14'))->toWebPush($this->user, new DailyReminder($this->family, '2026-03-14'))->toArray();

    expect($payload['title'])->toBe('PhotoDaily')
        ->and($payload['body'])->toBe('Oggi manca ancora la foto di Gio 📷')
        ->and($payload['data'])->toBe(['url' => '/carica?data=2026-03-14'])
        ->and($payload['tag'])->toBe('promemoria-2026-03-14');

    $this->family->update(['protagonist_name' => null]);
    expect((new DailyReminder($this->family->fresh(), '2026-03-14'))->body())->toBe('Oggi manca ancora la foto del giorno 📷');
});

it('forgets subscriptions that the push service reports as gone', function (int $status) {
    $subscription = PushSubscription::sole();
    $report = new MessageSentReport(
        new PsrRequest('POST', $subscription->endpoint),
        new PsrResponse($status),
        false,
        'Gone',
    );

    app(ReportHandler::class)->handleReport($report, $subscription, new WebPushMessage);

    expect(PushSubscription::count())->toBe(0);
})->with([404, 410]);

it('runs every fifteen minutes', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'photos:send-reminders'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/15 * * * *');
});

it('lets each user subscribe devices and choose the time', function () {
    config(['webpush.vapid.public_key' => 'chiave-pubblica']);
    $other = User::factory()->for($this->family)->create();
    Sanctum::actingAs($other);

    $this->getJson('/api/push/config')->assertJsonPath('data.public_key', 'chiave-pubblica');

    $this->postJson('/api/push/iscrizioni', [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz',
        'keys' => ['p256dh' => 'p', 'auth' => 'a'],
    ])->assertNoContent();
    $this->postJson('/api/push/iscrizioni', ['endpoint' => 'http://non-sicuro.example', 'keys' => ['p256dh' => 'p', 'auth' => 'a']])
        ->assertUnprocessable();

    expect($other->pushSubscriptions()->count())->toBe(1);

    $this->getJson('/api/me')->assertJsonPath('data.promemoria', ['attivo' => false, 'orario' => '20:30']);
    $this->patchJson('/api/me/promemoria', ['attivo' => true, 'orario' => '21:15'])
        ->assertOk()
        ->assertJsonPath('data.promemoria', ['attivo' => true, 'orario' => '21:15']);
    $this->patchJson('/api/me/promemoria', ['orario' => '9pm'])->assertUnprocessable();

    $this->deleteJson('/api/push/iscrizioni', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/xyz'])->assertNoContent();
    expect($other->pushSubscriptions()->count())->toBe(0);

    // Le iscrizioni degli altri non si toccano.
    expect($this->user->pushSubscriptions()->count())->toBe(1);
});
