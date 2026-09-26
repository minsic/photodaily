<?php

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoSequence;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    $this->family = Family::factory()->create(['slug' => 'giopellino']);
});

it('lists published photos by date in blocks, with a cursor for the next one', function () {
    $first = Photo::factory()->for($this->family)->create(['data' => '2024-01-01']);
    $sameDay = Photo::factory()->for($this->family)->special()->create(['data' => '2024-01-01']);
    $later = Photo::factory()->for($this->family)->create(['data' => '2024-03-05']);
    Photo::factory()->for($this->family)->draft()->create(['data' => '2024-02-01']);
    Photo::factory()->create(['data' => '2024-01-02']);

    Sanctum::actingAs(User::factory()->for($this->family)->create());

    $this->getJson('/api/photos/sequenza')
        ->assertOk()
        ->assertJsonPath('data.*.id', [$first->ulid, $sameDay->ulid, $later->ulid])
        ->assertJsonPath('next', null)
        ->assertJsonStructure(['data' => [['id', 'data', 'data_speciale', 'medium_url', 'thumbnail_url', 'medium_width', 'medium_height']]]);

    $this->getJson('/api/photos/sequenza?da=2024-01-01&a=2024-01-31')->assertJsonPath('data.*.id', [$first->ulid, $sameDay->ulid]);
    $this->getJson('/api/photos/sequenza?speciali=1')->assertJsonPath('data.*.id', [$sameDay->ulid]);
    $this->getJson("/api/photos/sequenza?dopo={$sameDay->ulid}")->assertJsonPath('data.*.id', [$later->ulid]);
    $this->getJson('/api/photos/sequenza?da=2024-02-01&a=2024-01-01')->assertUnprocessable();
});

it('pages by 100 and never follows a cursor of another family', function () {
    Photo::factory()->for($this->family)->count(PhotoSequence::PAGE_SIZE + 5)->sequence(
        fn ($sequence) => ['data' => now()->startOfYear()->addDays($sequence->index)->toDateString()],
    )->create();
    $foreign = Photo::factory()->create();

    Sanctum::actingAs(User::factory()->for($this->family)->create());

    $page = $this->getJson('/api/photos/sequenza')->assertOk();
    expect($page->json('data'))->toHaveCount(PhotoSequence::PAGE_SIZE)
        ->and($page->json('next'))->toBe($page->json('data.99.id'));

    $this->getJson('/api/photos/sequenza?dopo='.$page->json('next'))->assertJsonCount(5, 'data')->assertJsonPath('next', null);
    $this->getJson("/api/photos/sequenza?dopo={$foreign->ulid}")->assertExactJson(['data' => [], 'next' => null]);
});

it('works for public diaries and stays closed for private ones', function () {
    $photo = Photo::factory()->for($this->family)->create(['data' => '2024-01-01']);

    $this->getJson('/api/public/giopellino/photos/sequenza')->assertNotFound();

    $this->family->changeAccessMode(AccessMode::Public);

    $this->getJson('/api/public/giopellino/photos/sequenza')->assertOk()->assertJsonPath('data.0.id', $photo->ulid);
});
