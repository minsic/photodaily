<?php

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Services\MainImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    Sanctum::actingAs(User::factory()->for(Family::factory())->create());
});

function upload(UploadedFile $image): Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/photos', ['image' => $image, 'data' => '2024-05-01']);
}

it('shrinks to the max side in jpeg whatever arrives bigger, and never stores the raw original', function () {
    // 4096 in produzione: qui un decimo, per restare nella memoria dei test.
    config(['photodaily.main_max_side' => 410]);
    $image = imagecreatetruecolor(500, 300);
    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();

    upload(UploadedFile::fake()->createWithContent('grande.png', $png))->assertCreated();

    $photo = Photo::sole();
    $stored = Storage::disk('r2')->get($photo->image_path);

    expect(MainImage::detectFormat($stored))->toBe('jpeg')
        ->and([$photo->width, $photo->height])->toBe([410, 246])
        ->and(getimagesizefromstring($stored))->toMatchArray([0 => 410, 1 => 246])
        ->and($photo->size_bytes)->toBe(strlen($stored))
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(1 + 2); // principale, medium e miniatura
});

it('keeps a light photo exactly as it is', function () {
    $file = UploadedFile::fake()->image('leggera.jpg', 600, 400);
    $bytes = file_get_contents($file->getPathname());

    upload($file)->assertCreated();

    expect(Storage::disk('r2')->get(Photo::sole()->image_path))->toBe($bytes);
});

it('refuses files over 25 MB with a clear message', function () {
    upload(UploadedFile::fake()->image('enorme.jpg', 100, 100)->size(25 * 1024 + 1))
        ->assertUnprocessable()
        ->assertJsonPath('errors.image.0', 'La foto pesa più di 25 MB: scegline una più leggera.');
});

it('refuses what is not a photo, whatever the extension says', function () {
    upload(UploadedFile::fake()->createWithContent('finta.jpg', '%PDF-1.7 non una foto'))
        ->assertUnprocessable()
        ->assertJsonPath('errors.image.0', 'Formato non supportato: carica una foto JPG, PNG, WebP o HEIC.');
});

it('explains how to upload a heic when the server cannot read it', function () {
    $heic = "\0\0\0\x18ftypheic\0\0\0\0mif1heic".str_repeat("\0", 64);

    $response = upload(UploadedFile::fake()->createWithContent('IMG_0001.HEIC', $heic));

    if (MainImage::canReadHeic()) {
        // Con Imagick e libheif il file finto non si decodifica: resta un 422 chiaro.
        $response->assertUnprocessable();

        return;
    }

    $response->assertUnprocessable();
    expect($response->json('errors.image.0'))->toContain('HEIC')->toContain('JPG');
    expect(Photo::count())->toBe(0);
});

it('answers 422, not 500, when a photo looks fine but cannot be decoded', function () {
    upload(UploadedFile::fake()->createWithContent('rotta.jpg', "\xFF\xD8\xFF\xE0".str_repeat("\x42", 300)))
        ->assertUnprocessable()
        ->assertJsonPath('errors.image.0', fn (string $message) => str_contains($message, 'Non riusciamo a leggere'));

    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});
