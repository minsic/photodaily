<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ThumbnailsTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        $this->family = Family::factory()->create();

        Sanctum::actingAs(User::factory()->for($this->family)->create());
    }

    public function test_upload_also_stores_a_thumbnail(): void
    {
        $response = $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $this->assertNotNull($photo->thumbnail_path);
        $this->assertStringStartsWith("families/{$this->family->id}/photos/thumbs/", $photo->thumbnail_path);
        Storage::disk('r2')->assertExists($photo->thumbnail_path);

        $this->assertGreaterThan(0, $photo->thumbnail_bytes);
        $this->assertSame(Storage::disk('r2')->size($photo->thumbnail_path), $photo->thumbnail_bytes);

        // Il lato lungo della miniatura è 400px.
        [$width, $height] = getimagesizefromstring(Storage::disk('r2')->get($photo->thumbnail_path));
        $this->assertSame(400, max($width, $height));

        $this->assertNotEmpty($response->json('data.thumbnail_url'));
        $this->assertNotEmpty($response->json('data.image_url'));
    }

    public function test_listing_returns_only_the_thumbnail_while_the_detail_returns_both(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $list = $this->getJson('/api/photos')->assertOk();

        $this->assertNotEmpty($list->json('data.0.thumbnail_url'));
        $this->assertArrayNotHasKey('image_url', $list->json('data.0'));

        $detail = $this->getJson("/api/photos/{$photo->id}")->assertOk();

        $this->assertNotEmpty($detail->json('data.thumbnail_url'));
        $this->assertNotEmpty($detail->json('data.image_url'));
    }

    public function test_thumbnail_url_falls_back_to_the_original_until_it_is_generated(): void
    {
        $photo = Photo::factory()->for($this->family)->create(['thumbnail_path' => null]);
        Storage::disk('r2')->put($photo->image_path, 'contenuto');

        $url = $this->getJson('/api/photos')->assertOk()->json('data.0.thumbnail_url');

        $this->assertStringContainsString(basename($photo->image_path), $url);
    }

    public function test_deleting_a_photo_removes_the_thumbnail_too(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $this->deleteJson("/api/photos/{$photo->id}")->assertNoContent();

        Storage::disk('r2')->assertMissing($photo->image_path);
        Storage::disk('r2')->assertMissing($photo->thumbnail_path);
    }

    public function test_command_generates_missing_thumbnails_and_can_be_run_again(): void
    {
        $photos = $this->photosWithoutThumbnail(3);
        $other = Photo::factory()->create();
        Storage::disk('r2')->put($other->image_path, 'non è un\'immagine');

        $this->artisan('photos:generate-thumbnails', ['family' => $this->family->slug])->assertSuccessful();

        foreach ($photos as $photo) {
            $photo->refresh();

            $this->assertNotNull($photo->thumbnail_path);
            Storage::disk('r2')->assertExists($photo->thumbnail_path);
        }

        $this->assertNull($other->fresh()->thumbnail_path, 'Le altre famiglie non vengono toccate.');

        $generated = collect($photos)->map(fn (Photo $photo) => $photo->fresh()->only(['thumbnail_path', 'thumbnail_bytes']));

        // Rilanciandolo non rifà quelle già fatte.
        $this->artisan('photos:generate-thumbnails', ['family' => $this->family->slug])
            ->expectsOutputToContain('Nessuna miniatura da generare')
            ->assertSuccessful();

        $this->assertSame(
            $generated->all(),
            collect($photos)->map(fn (Photo $photo) => $photo->fresh()->only(['thumbnail_path', 'thumbnail_bytes']))->all(),
        );

        $this->assertSame(3, Photo::whereNotNull('thumbnail_path')->count());
        $this->assertCount(3, Storage::disk('r2')->files("families/{$this->family->id}/photos/thumbs"));
    }

    public function test_command_reports_images_it_cannot_process(): void
    {
        $broken = Photo::factory()->for($this->family)->create(['thumbnail_path' => null]);
        Storage::disk('r2')->put($broken->image_path, 'questo non è un JPEG');

        $this->artisan('photos:generate-thumbnails')
            ->expectsOutputToContain("foto {$broken->id}")
            ->assertFailed();

        $this->assertNull($broken->fresh()->thumbnail_path);
    }

    public function test_thumbnails_count_towards_the_family_storage(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200)->size(1024),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();
        $expected = round(($photo->size_bytes + $photo->thumbnail_bytes) / 1024 / 1024, 2);

        $this->assertSame($expected, $this->family->fresh()->storage_used_mb);
    }

    /**
     * @return list<Photo>
     */
    private function photosWithoutThumbnail(int $count): array
    {
        $photos = [];

        foreach (range(1, $count) as $index) {
            $photo = Photo::factory()->for($this->family)->create([
                'thumbnail_path' => null,
                'data' => sprintf('2024-05-%02d', $index),
            ]);

            Storage::disk('r2')->put(
                $photo->image_path,
                UploadedFile::fake()->image('originale.jpg', 1200, 900)->get(),
            );

            $photos[] = $photo;
        }

        return $photos;
    }
}
