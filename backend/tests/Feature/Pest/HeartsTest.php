<?php

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Photo;
use App\Models\PhotoHeart;
use App\Models\User;
use App\Services\FamilyReadTokens;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    $this->family = Family::factory()->create(['slug' => 'giopellino']);
    $this->mamma = User::factory()->for($this->family)->create(['name' => 'Mamma']);
    $this->nonna = User::factory()->for($this->family)->create(['name' => 'Nonna']);
    $this->photo = Photo::factory()->for($this->family)->create(['data' => '2024-05-01']);
});

it('puts and removes a heart, and repeating does nothing more', function () {
    Sanctum::actingAs($this->mamma);

    $this->putJson("/api/photos/{$this->photo->ulid}/cuore")
        ->assertOk()
        ->assertExactJson(['data' => ['cuori' => 1, 'mio_cuore' => true, 'cuori_da' => []]]);
    $this->putJson("/api/photos/{$this->photo->ulid}/cuore")->assertJsonPath('data.cuori', 1);

    $this->deleteJson("/api/photos/{$this->photo->ulid}/cuore")
        ->assertExactJson(['data' => ['cuori' => 0, 'mio_cuore' => false, 'cuori_da' => []]]);
    $this->deleteJson("/api/photos/{$this->photo->ulid}/cuore")->assertJsonPath('data.cuori', 0);
});

it('tells members how many hearts, whether one is theirs and who put the others', function () {
    PhotoHeart::create(['photo_id' => $this->photo->id, 'user_id' => $this->nonna->id]);
    Sanctum::actingAs($this->mamma);
    $this->putJson("/api/photos/{$this->photo->ulid}/cuore");

    $this->getJson("/api/photos/{$this->photo->ulid}")
        ->assertJsonPath('data.cuori', 2)
        ->assertJsonPath('data.mio_cuore', true)
        ->assertJsonPath('data.cuori_da', ['Nonna']);

    $this->getJson('/api/photos')
        ->assertJsonPath('data.0.cuori', 2)
        ->assertJsonPath('data.0.mio_cuore', true)
        ->assertJsonMissingPath('data.0.cuori_da');
});

it('shows only the count on the public diary, and nobody can react from there', function () {
    PhotoHeart::create(['photo_id' => $this->photo->id, 'user_id' => $this->nonna->id]);
    $this->family->changeAccessMode(AccessMode::Password, 'password-di-famiglia');
    [$readToken] = app(FamilyReadTokens::class)->issue($this->family->fresh());

    $this->withToken($readToken)->getJson('/api/public/giopellino/photos')
        ->assertJsonPath('data.0.cuori', 1)
        ->assertJsonMissingPath('data.0.mio_cuore');
    $this->withToken($readToken)->getJson("/api/public/giopellino/photos/{$this->photo->ulid}")
        ->assertJsonPath('data.cuori', 1)
        ->assertJsonMissingPath('data.cuori_da');

    $this->withToken($readToken)->putJson("/api/photos/{$this->photo->ulid}/cuore")->assertUnauthorized();
    $this->putJson("/api/public/giopellino/photos/{$this->photo->ulid}/cuore")->assertNotFound();
    expect(PhotoHeart::count())->toBe(1);
});

it('answers 404 on photos of another family, like everywhere else', function () {
    $foreign = Photo::factory()->create();
    Sanctum::actingAs($this->mamma);

    $this->putJson("/api/photos/{$foreign->ulid}/cuore")->assertNotFound();
    $this->deleteJson("/api/photos/{$foreign->ulid}/cuore")->assertNotFound();
    expect(PhotoHeart::count())->toBe(0);
});

it('removes the hearts with the photo and with the person', function () {
    PhotoHeart::create(['photo_id' => $this->photo->id, 'user_id' => $this->nonna->id]);
    $this->nonna->delete();
    expect(PhotoHeart::count())->toBe(0);

    PhotoHeart::create(['photo_id' => $this->photo->id, 'user_id' => $this->mamma->id]);
    $this->photo->delete();
    expect(PhotoHeart::count())->toBe(0);
});

it('lists a whole page of hearts with a fixed number of queries', function () {
    Photo::factory()->for($this->family)->count(30)->create()->each(
        fn (Photo $photo) => PhotoHeart::create(['photo_id' => $photo->id, 'user_id' => $this->nonna->id]),
    );
    Sanctum::actingAs($this->mamma);

    DB::enableQueryLog();
    $this->getJson('/api/photos?per_page=50')->assertOk();

    expect(collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'photo_hearts'))->count())->toBe(1);
});
