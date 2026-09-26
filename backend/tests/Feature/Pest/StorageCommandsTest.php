<?php

use App\Models\Family;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('r2');
    // 4096 in produzione: qui piccolo, per restare nella memoria dei test.
    config(['photodaily.main_max_side' => 200]);
});

function storedPng(Family $family, int $width, int $height, string $name): string
{
    $image = imagecreatetruecolor($width, $height);

    // Rumore: un PNG a tinta unita peserebbe meno del JPEG.
    for ($i = 0; $i < 4000; $i++) {
        imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, 0xFFFFFF));
    }

    ob_start();
    imagepng($image);
    $path = "families/{$family->id}/photos/{$name}.png";
    Storage::disk('r2')->put($path, (string) ob_get_clean());

    return $path;
}

it('measures without touching anything in dry run, then shrinks a shared file once', function () {
    $family = Family::factory()->create();
    $big = storedPng($family, 400, 300, 'grande');
    $small = storedPng($family, 150, 100, 'piccola');
    $bigBytes = Storage::disk('r2')->size($big);

    $published = Photo::factory()->for($family)->create(['image_path' => $big, 'size_bytes' => $bigBytes, 'width' => 400, 'height' => 300]);
    $draft = Photo::factory()->for($family)->create(['image_path' => $big, 'size_bytes' => $bigBytes, 'width' => 400, 'height' => 300, 'is_draft' => true]);
    Photo::factory()->for($family)->create(['image_path' => $small, 'size_bytes' => Storage::disk('r2')->size($small), 'width' => 150, 'height' => 100]);

    $this->artisan('photos:shrink-originals', ['--family' => $family->slug, '--dry-run' => true])
        ->expectsOutputToContain('Si libererebbero')
        ->assertSuccessful();

    expect(Storage::disk('r2')->exists($big))->toBeTrue()
        ->and($published->fresh()->image_path)->toBe($big);

    $this->artisan('photos:shrink-originals', ['--family' => $family->slug])->assertSuccessful();

    $published->refresh();
    $draft->refresh();

    expect($published->image_path)->not->toBe($big)
        ->and($draft->image_path)->toBe($published->image_path)
        ->and([$published->width, $published->height])->toBe([200, 150])
        ->and($published->size_bytes)->toBe(Storage::disk('r2')->size($published->image_path))
        ->and($published->size_bytes)->toBeLessThan($bigBytes)
        ->and(Storage::disk('r2')->exists($big))->toBeFalse()
        ->and(Storage::disk('r2')->exists($small))->toBeTrue()
        ->and($family->fresh()->storage_used_mb)->toBe(round($family->storageUsedBytes() / 1024 / 1024, 2));
});

it('recalculates the storage of every family from the three versions', function () {
    $family = Family::factory()->create();
    Photo::factory()->for($family)->create(['size_bytes' => 2 * 1024 * 1024, 'medium_bytes' => 512 * 1024, 'thumbnail_bytes' => 512 * 1024]);
    $family->forceFill(['storage_used_mb' => 999])->save();

    $this->artisan('photos:recalc-storage')->expectsOutputToContain('999')->assertSuccessful();

    expect($family->fresh()->storage_used_mb)->toBe(3.0);
});
