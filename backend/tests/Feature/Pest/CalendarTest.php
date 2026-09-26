<?php

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');

    // 23:30 UTC del 14 marzo: a Roma è già il 15, a New York è ancora il 14.
    $this->travelTo(new DateTimeImmutable('2026-03-14 23:30:00', new DateTimeZone('UTC')));

    $this->family = Family::factory()->create(['protagonist_birthdate' => '2026-03-01']);
    Sanctum::actingAs(User::factory()->for($this->family)->create());
});

function photoOn(Family $family, string $day, array $attributes = []): Photo
{
    return Photo::factory()->for($family)->create(['data' => $day, ...$attributes]);
}

it('lists full, empty and draft days from the birth up to today in the family timezone', function () {
    $first = photoOn($this->family, '2026-03-01');
    photoOn($this->family, '2026-03-03', ['data_speciale' => true]);
    $older = photoOn($this->family, '2026-03-03');
    photoOn($this->family, '2026-03-10', ['is_draft' => true]);

    $calendar = $this->getJson('/api/photos/calendario?anno=2026')->assertOk()->json('data');

    expect($calendar['oggi'])->toBe('2026-03-15')
        ->and($calendar['inizio'])->toBe('2026-03-01')
        ->and($calendar['fine'])->toBe('2026-03-15')
        ->and($calendar['totali'])->toBe(15)
        ->and($calendar['pieni'])->toBe(2)
        ->and($calendar['vuoti'])->toHaveCount(13)
        ->and($calendar['vuoti'])->not->toContain('2026-03-01', '2026-03-03')
        ->and($calendar['vuoti'])->toContain('2026-03-10', '2026-03-15')
        ->and($calendar['giorni'])->toBe([
            ['data' => '2026-03-01', 'foto_id' => $first->ulid, 'foto' => 1, 'speciale' => false],
            // Due foto lo stesso giorno: si apre la più recente, e si dice quante sono.
            ['data' => '2026-03-03', 'foto_id' => $older->ulid, 'foto' => 2, 'speciale' => true],
        ])
        ->and($calendar['bozze'])->toHaveCount(1)
        ->and($calendar['bozze'][0]['data'])->toBe('2026-03-10');
});

it('uses the timezone of the family to decide what today is', function () {
    $this->family->update(['timezone' => 'America/New_York']);

    $calendar = $this->getJson('/api/photos/calendario')->assertOk()->json('data');

    expect($calendar['anno'])->toBe(2026)
        ->and($calendar['oggi'])->toBe('2026-03-14')
        ->and($calendar['totali'])->toBe(14);
});

it('counts a whole past year, from the first photo when the birthdate is missing', function () {
    $this->family->update(['protagonist_birthdate' => null]);
    photoOn($this->family, '2024-12-01');
    photoOn($this->family, '2024-12-02');
    photoOn($this->family, '2025-06-01');

    $y2024 = $this->getJson('/api/photos/calendario?anno=2024')->json('data');
    $y2025 = $this->getJson('/api/photos/calendario?anno=2025')->json('data');

    expect($y2024['inizio'])->toBe('2024-12-01')
        ->and($y2024['totali'])->toBe(31)
        ->and($y2024['pieni'])->toBe(2)
        ->and($y2025['inizio'])->toBe('2025-01-01')
        ->and($y2025['fine'])->toBe('2025-12-31')
        ->and($y2025['totali'])->toBe(365)
        ->and($y2025['pieni'])->toBe(1);
});

it('has no days to fill before the start or in the future', function () {
    $this->family->update(['protagonist_birthdate' => null]);

    expect($this->getJson('/api/photos/calendario?anno=2026')->json('data'))
        ->toMatchArray(['inizio' => null, 'fine' => null, 'vuoti' => [], 'pieni' => 0, 'totali' => 0]);

    photoOn($this->family, '2025-01-01');

    expect($this->getJson('/api/photos/calendario?anno=2027')->json('data'))
        ->toMatchArray(['inizio' => null, 'vuoti' => [], 'totali' => 0]);
});

it('shows only the photos of the users family and validates the year', function () {
    photoOn(Family::factory()->create(), '2026-03-05');

    expect($this->getJson('/api/photos/calendario?anno=2026')->json('data.giorni'))->toBe([]);

    $this->getJson('/api/photos/calendario?anno=abc')->assertUnprocessable();
    $this->getJson('/api/photos/calendario?anno=1800')->assertUnprocessable();
});

it('refuses photos dated in the future of the family, not of the server', function () {
    $upload = fn (string $day) => $this->postJson('/api/photos', [
        'image' => UploadedFile::fake()->image('foto.jpg', 800, 600),
        'data' => $day,
    ]);

    // A Roma è già il 15.
    $upload('2026-03-15')->assertCreated();
    $upload('2026-03-16')->assertUnprocessable()->assertJsonPath('errors.data.0', 'La data non può essere nel futuro.');

    // A New York è ancora il 14. L'utente si ricarica come a ogni richiesta
    // vera: quello del test terrebbe in memoria la famiglia di prima.
    $this->family->update(['timezone' => 'America/New_York']);
    Sanctum::actingAs(User::query()->where('family_id', $this->family->id)->first());
    $upload('2026-03-15')->assertUnprocessable();
});

it('lets an admin change the timezone and exposes it', function () {
    Sanctum::actingAs(User::factory()->admin()->for($this->family)->create());

    $this->patchJson('/api/family', ['timezone' => 'Europe/London'])->assertOk()->assertJsonPath('data.timezone', 'Europe/London');
    $this->patchJson('/api/family', ['timezone' => 'Europa/Roma'])->assertUnprocessable()
        ->assertJsonPath('errors.timezone.0', 'Fuso orario non riconosciuto.');

    $this->getJson('/api/me')->assertJsonPath('data.family.timezone', 'Europe/London');
});
