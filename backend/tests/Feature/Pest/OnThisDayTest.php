<?php

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    // 23:30 del 26 settembre a UTC: a Roma è già il 27.
    $this->travelTo(new DateTimeImmutable('2026-09-26 23:30:00', new DateTimeZone('UTC')));
    $this->family = Family::factory()->create(['slug' => 'giopellino', 'timezone' => 'Europe/Rome']);
});

function photoAt(Family $family, string $day, array $attributes = []): Photo
{
    return Photo::factory()->for($family)->create(['data' => $day, ...$attributes]);
}

it('shows the same day of every past year, one photo each, the special first', function () {
    photoAt($this->family, '2025-09-27');
    $special = photoAt($this->family, '2025-09-27', ['data_speciale' => true]);
    photoAt($this->family, '2025-09-27');
    $twoYears = photoAt($this->family, '2024-09-27');
    photoAt($this->family, '2023-09-26');
    photoAt($this->family, '2022-09-27', ['is_draft' => true]);
    photoAt($this->family, '2026-09-27');
    photoAt(Family::factory()->create(), '2021-09-27');

    Sanctum::actingAs(User::factory()->for($this->family)->create());

    $response = $this->getJson('/api/photos/anni-fa')->assertOk();

    expect($response->json('data.oggi'))->toBe('2026-09-27')
        ->and($response->json('data.anni'))->toHaveCount(2)
        ->and($response->json('data.anni.0'))->toMatchArray(['anni' => 1, 'data' => '2025-09-27', 'id' => $special->ulid, 'foto' => 3, 'speciale' => true])
        ->and($response->json('data.anni.1'))->toMatchArray(['anni' => 2, 'data' => '2024-09-27', 'id' => $twoYears->ulid, 'foto' => 1])
        ->and($response->json('data.anni.0.thumbnail_url'))->toBeString();
});

it('handles the 29th of february only in leap years', function () {
    $this->travelTo(new DateTimeImmutable('2028-02-29 12:00:00', new DateTimeZone('Europe/Rome')));
    photoAt($this->family, '2024-02-29');
    photoAt($this->family, '2025-02-28');

    Sanctum::actingAs(User::factory()->for($this->family)->create());

    $this->getJson('/api/photos/anni-fa')->assertJsonPath('data.anni.*.data', ['2024-02-29']);
});

it('is empty for a family without photos, and public diaries show it too', function () {
    Sanctum::actingAs(User::factory()->for($this->family)->create());
    $this->getJson('/api/photos/anni-fa')->assertJsonPath('data.anni', []);

    photoAt($this->family, '2025-09-27');
    $this->app['auth']->forgetGuards();

    $this->getJson('/api/public/giopellino/photos/anni-fa')->assertNotFound();

    $this->family->changeAccessMode(AccessMode::Public);
    $this->getJson('/api/public/giopellino/photos/anni-fa')->assertOk()->assertJsonPath('data.anni.0.anni', 1);
});
