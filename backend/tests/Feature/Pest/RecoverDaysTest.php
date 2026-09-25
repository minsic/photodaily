<?php

use App\Jobs\GeneratePhotoVariants;
use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Services\PhotoStorage;
use App\Support\ImageMetadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('r2');
    $this->travelTo(new DateTimeImmutable('2026-03-31 12:00:00', new DateTimeZone('Europe/Rome')));

    $this->family = Family::factory()->create(['protagonist_birthdate' => '2026-03-01']);
    Sanctum::actingAs(User::factory()->for($this->family)->create());
});

/** Blocco EXIF (TIFF little endian) con orientamento 6 e coordinate GPS. */
function exifWithGps(): string
{
    $u16 = fn (int $v) => pack('v', $v);
    $u32 = fn (int $v) => pack('V', $v);
    $entry = fn (int $tag, int $type, int $count, string $value) => $u16($tag).$u16($type).$u32($count).str_pad($value, 4, "\0");
    $rational = fn (int $num, int $den) => $u32($num).$u32($den);

    $gpsOffset = 8 + 2 + 2 * 12 + 4;
    $dataOffset = $gpsOffset + 2 + 4 * 12 + 4;

    $tiff = 'II'.$u16(42).$u32(8)
        .$u16(2)
        .$entry(0x0112, 3, 1, $u16(6))
        .$entry(0x8825, 4, 1, $u32($gpsOffset))
        .$u32(0)
        .$u16(4)
        .$entry(0x0001, 2, 2, "N\0")
        .$entry(0x0002, 5, 3, $u32($dataOffset))
        .$entry(0x0003, 2, 2, "E\0")
        .$entry(0x0004, 5, 3, $u32($dataOffset + 24))
        .$u32(0)
        .$rational(45, 1).$rational(27, 1).$rational(3012, 100)
        .$rational(9, 1).$rational(11, 1).$rational(1534, 100);

    return "Exif\0\0".$tiff;
}

function jpegWithMetadata(bool $xmp = false): string
{
    $image = imagecreatetruecolor(640, 480);
    imagefilledrectangle($image, 0, 0, 320, 480, 0xC05040);
    ob_start();
    imagejpeg($image, null, 90);
    $jpeg = (string) ob_get_clean();

    $app1 = fn (string $payload) => "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    $segments = $app1(exifWithGps());

    if ($xmp) {
        $segments .= $app1("http://ns.adobe.com/xap/1.0/\0<x:xmpmeta><exif:GPSLatitude>45,27.5N</exif:GPSLatitude></x:xmpmeta>");
    }

    return "\xFF\xD8".$segments.substr($jpeg, 2);
}

function exifOf(string $bytes): array
{
    $path = tempnam(sys_get_temp_dir(), 'exif').'.jpg';
    file_put_contents($path, $bytes);
    $exif = @exif_read_data($path, null, true) ?: [];
    @unlink($path);

    return $exif;
}

/** Tutto quello che viene dopo SOS: i dati compressi dell'immagine. */
function scanData(string $jpeg): string
{
    return substr($jpeg, strpos($jpeg, "\xFF\xDA"));
}

it('removes the gps coordinates from a jpeg without touching orientation or pixels', function () {
    $original = jpegWithMetadata();
    expect(exifOf($original)['GPS']['GPSLatitude'] ?? null)->not->toBeNull();

    $this->postJson('/api/photos', [
        'image' => UploadedFile::fake()->createWithContent('scatto.jpg', $original),
        'data' => '2026-03-02',
    ])->assertCreated();

    $stored = Storage::disk('r2')->get(Photo::sole()->image_path);
    $exif = exifOf($stored);

    expect($exif['GPS']['GPSLatitude'] ?? null)->toBeNull()
        ->and($exif['GPS']['GPSLongitude'] ?? null)->toBeNull()
        ->and($exif['IFD0']['Orientation'] ?? null)->toBe(6)
        ->and(strlen($stored))->toBe(strlen($original))
        ->and(scanData($stored))->toBe(scanData($original));
});

it('drops the xmp block that may repeat the position', function () {
    $clean = ImageMetadata::withoutLocation(jpegWithMetadata(xmp: true));

    expect($clean)->not->toContain('GPSLatitude')
        ->and($clean)->not->toContain('ns.adobe.com')
        ->and(imagecreatefromstring($clean))->not->toBeFalse();
});

it('strips metadata chunks from png and webp', function () {
    $image = imagecreatetruecolor(40, 30);

    ob_start();
    imagepng($image);
    $png = (string) ob_get_clean();
    $chunk = fn (string $type, string $data) => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    $withText = substr($png, 0, -12).$chunk('tEXt', "GPS\0045.4575N 9.1918E").$chunk('eXIf', substr(exifWithGps(), 6)).substr($png, -12);

    $cleanPng = ImageMetadata::withoutLocation($withText);
    expect($cleanPng)->toBe($png)->and(imagecreatefromstring($cleanPng))->not->toBeFalse();

    ob_start();
    imagewebp($image);
    $webp = (string) ob_get_clean();
    $exif = substr(exifWithGps(), 6);
    $body = substr($webp, 12).'EXIF'.pack('V', strlen($exif)).$exif.(strlen($exif) % 2 ? "\0" : '');
    $withExif = 'RIFF'.pack('V', strlen($body) + 4).'WEBP'.$body;

    $cleanWebp = ImageMetadata::withoutLocation($withExif);
    expect($cleanWebp)->toBe($webp)->and(imagecreatefromstring($cleanWebp))->not->toBeFalse();
});

it('answers the upload right away and leaves the variants to the queue', function () {
    Queue::fake();

    $response = $this->postJson('/api/photos', [
        'image' => UploadedFile::fake()->image('foto.jpg', 2000, 1500),
        'data' => '2026-03-02',
    ])->assertCreated();

    $photo = Photo::sole();

    expect($photo->thumbnail_path)->toBeNull()
        ->and($photo->medium_path)->toBeNull()
        // Finché il job non gira si vede l'originale.
        ->and($response->json('data.thumbnail_url'))->toContain(basename($photo->image_path));

    Queue::assertPushed(GeneratePhotoVariants::class, fn ($job) => $job->photo->is($photo));

    (new GeneratePhotoVariants($photo))->handle(app(PhotoStorage::class));

    expect($photo->fresh()->thumbnail_path)->not->toBeNull()
        ->and($photo->fresh()->medium_path)->not->toBeNull();
});

it('does nothing when the photo was deleted before the job ran', function () {
    expect((new GeneratePhotoVariants(new Photo))->deleteWhenMissingModels)->toBeTrue();
});

it('takes 30 photos over three weeks with some days already full', function () {
    Queue::fake();

    // Tre giorni hanno già la loro foto.
    foreach (['2026-03-05', '2026-03-12', '2026-03-19'] as $full) {
        Photo::factory()->for($this->family)->create(['data' => $full]);
    }

    // 30 foto dal 2 al 22 marzo: il recupero ne carica anche due o tre per
    // giorno se lo si sceglie, e il server le accetta tutte con la loro data.
    $dates = [];
    foreach (range(0, 29) as $i) {
        $dates[] = sprintf('2026-03-%02d', 2 + intdiv($i * 21, 30));
    }

    foreach ($dates as $i => $day) {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image("scatto-{$i}.jpg", 1200, 900),
            'data' => $day,
            'didascalia' => $i % 7 === 0 ? "Giorno {$day}" : null,
        ])->assertCreated()->assertJsonPath('data.data', $day);
    }

    expect(Photo::where('family_id', $this->family->id)->count())->toBe(33);
    Queue::assertPushed(GeneratePhotoVariants::class, 30);

    $calendar = $this->getJson('/api/photos/calendario?anno=2026')->json('data');

    // Dal 1° al 31 marzo: pieni tutti i giorni dal 2 al 22.
    expect($calendar['totali'])->toBe(31)
        ->and($calendar['pieni'])->toBe(21)
        ->and($calendar['vuoti'])->toContain('2026-03-01', '2026-03-23', '2026-03-31')
        ->and($calendar['vuoti'])->not->toContain('2026-03-05', '2026-03-12');

    $withTwo = collect($calendar['giorni'])->firstWhere('data', '2026-03-05');
    expect($withTwo['foto'])->toBeGreaterThanOrEqual(2);
});
