<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Services\ThumbnailMaker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Image;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MediumImagesTest extends TestCase
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

    public function test_upload_stores_a_medium_version_next_to_the_thumbnail(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 3000, 2000),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $this->assertStringStartsWith("families/{$this->family->id}/photos/medium/", $photo->medium_path);
        Storage::disk('r2')->assertExists($photo->medium_path);
        $this->assertSame(Storage::disk('r2')->size($photo->medium_path), $photo->medium_bytes);

        [$width, $height] = getimagesizefromstring(Storage::disk('r2')->get($photo->medium_path));
        $this->assertSame([1400, 933], [$width, $height]);
        $this->assertSame([1400, 933], [$photo->medium_width, $photo->medium_height]);

        // La miniatura resta a 400px anche se ora si ricava dalla versione media.
        [$thumbWidth] = getimagesizefromstring(Storage::disk('r2')->get($photo->thumbnail_path));
        $this->assertSame(400, $thumbWidth);
    }

    public function test_detailed_photos_drop_quality_to_stay_under_the_weight_limit(): void
    {
        // Rumore casuale: il caso peggiore per la compressione.
        $image = imagecreatetruecolor(3000, 2000);
        for ($y = 0; $y < 2000; $y += 2) {
            for ($x = 0; $x < 3000; $x += 2) {
                imagefilledrectangle($image, $x, $y, $x + 1, $y + 1, random_int(0, 0xFFFFFF));
            }
        }
        $path = tempnam(sys_get_temp_dir(), 'rumore').'.png';
        imagepng($image, $path, 1);
        unset($image);

        // Come fa ThumbnailMaker: GD decodifica in una bitmap non compressa.
        ini_set('memory_limit', config('photodaily.thumbnail_memory_limit'));

        $atDefaultQuality = strlen(Image::fromPath($path)->scale(1400, 1400)->optimize('webp', ThumbnailMaker::QUALITY)->toBytes());
        $medium = app(ThumbnailMaker::class)->fromPath($path, ThumbnailMaker::MEDIUM_SIDE);
        @unlink($path);

        // Il rumore puro non entra nel limite nemmeno alla qualità più bassa:
        // qui si verifica che la qualità venga abbassata, non il peso assoluto.
        $this->assertGreaterThan(ThumbnailMaker::MEDIUM_MAX_BYTES, $atDefaultQuality);
        $this->assertSame(1400, $medium['width']);
        $this->assertLessThan($atDefaultQuality * 0.8, strlen($medium['bytes']));
    }

    public function test_thumbnails_keep_the_default_quality_whatever_their_weight(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'foto').'.jpg';
        file_put_contents($path, UploadedFile::fake()->image('foto.jpg', 1600, 1200)->get());

        $thumbnail = app(ThumbnailMaker::class)->fromPath($path);
        $expected = Image::fromPath($path)->orient()->scale(400, 400)->optimize('webp', ThumbnailMaker::QUALITY)->toBytes();
        @unlink($path);

        $this->assertSame(strlen($expected), strlen($thumbnail['bytes']));
    }

    public function test_small_photos_are_never_enlarged(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('piccola.jpg', 800, 600),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $this->assertSame([800, 600], [$photo->medium_width, $photo->medium_height]);
    }

    public function test_list_and_detail_expose_the_medium_url_but_only_the_detail_the_original(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $item = $this->getJson('/api/photos')->assertOk()->json('data.0');
        $this->assertStringContainsString('/medium/', $item['medium_url']);
        $this->assertSame([1400, 1050], [$item['medium_width'], $item['medium_height']]);
        $this->assertArrayNotHasKey('image_url', $item);

        $detail = $this->getJson("/api/photos/{$photo->id}")->assertOk()->json('data');
        $this->assertStringContainsString('/medium/', $detail['medium_url']);
        $this->assertNotEmpty($detail['image_url']);
    }

    public function test_medium_url_is_null_until_it_is_generated(): void
    {
        Photo::factory()->for($this->family)->create();

        $this->getJson('/api/photos')->assertOk()->assertJsonPath('data.0.medium_url', null);
    }

    public function test_deleting_a_photo_removes_the_medium_version_too(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();

        $this->deleteJson("/api/photos/{$photo->id}")->assertNoContent();

        Storage::disk('r2')->assertMissing($photo->medium_path);
    }

    public function test_medium_version_counts_towards_the_family_storage(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 1600, 1200)->size(1024),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::sole();
        $expected = round(($photo->size_bytes + $photo->thumbnail_bytes + $photo->medium_bytes) / 1024 / 1024, 2);

        $this->assertSame($expected, $this->family->fresh()->storage_used_mb);
    }

    public function test_command_generates_missing_medium_versions_and_can_be_run_again(): void
    {
        $photos = $this->photosWithoutMedium(3);
        $other = Photo::factory()->create();
        Storage::disk('r2')->put($other->image_path, UploadedFile::fake()->image('altra.jpg', 1600, 1200)->get());

        $this->artisan('photos:generate-medium', ['--family' => $this->family->slug])->assertSuccessful();

        foreach ($photos as $photo) {
            $photo->refresh();

            Storage::disk('r2')->assertExists($photo->medium_path);
            $this->assertSame([1400, 1050], [$photo->medium_width, $photo->medium_height]);
        }

        $this->assertNull($other->fresh()->medium_path, 'Le altre famiglie non vengono toccate.');
        $this->assertGreaterThan(0, $this->family->fresh()->storage_used_mb);

        $generated = collect($photos)->map(fn (Photo $photo) => $photo->fresh()->only(['medium_path', 'medium_bytes']));

        $this->artisan('photos:generate-medium', ['--family' => $this->family->slug])
            ->expectsOutputToContain('Nessuna versione media da generare')
            ->assertSuccessful();

        $this->assertSame(
            $generated->all(),
            collect($photos)->map(fn (Photo $photo) => $photo->fresh()->only(['medium_path', 'medium_bytes']))->all(),
        );
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->photosWithoutMedium(2);

        $this->artisan('photos:generate-medium', ['--dry-run' => true])
            ->expectsOutputToContain('nessun file generato')
            ->assertSuccessful();

        $this->assertSame(0, Photo::whereNotNull('medium_path')->count());
        $this->assertSame([], Storage::disk('r2')->files("families/{$this->family->id}/photos/medium"));
    }

    public function test_command_reports_images_it_cannot_process(): void
    {
        $broken = Photo::factory()->for($this->family)->create();
        Storage::disk('r2')->put($broken->image_path, 'questo non è un JPEG');

        $this->artisan('photos:generate-medium')
            ->expectsOutputToContain("foto {$broken->id}")
            ->assertFailed();

        $this->assertNull($broken->fresh()->medium_path);
    }

    public function test_unknown_family_is_an_error(): void
    {
        $this->artisan('photos:generate-medium', ['--family' => 'nessuno'])->assertFailed();
    }

    /**
     * @return list<Photo>
     */
    private function photosWithoutMedium(int $count): array
    {
        $photos = [];

        foreach (range(1, $count) as $index) {
            $photo = Photo::factory()->for($this->family)->create(['data' => sprintf('2024-05-%02d', $index)]);

            Storage::disk('r2')->put(
                $photo->image_path,
                UploadedFile::fake()->image('originale.jpg', 1600, 1200)->get(),
            );

            $photos[] = $photo;
        }

        return $photos;
    }
}
