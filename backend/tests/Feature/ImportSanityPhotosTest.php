<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Photo;
use App\Models\Plan;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ImportSanityPhotosTest extends TestCase
{
    use RefreshDatabase;

    private string $exportDir;

    private Family $family;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        $this->family = Family::factory()->create(['slug' => 'giopellino']);
        $this->exportDir = storage_path('framework/testing/sanity-export-'.Str::random(8));
        File::ensureDirectoryExists($this->exportDir.'/images');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->exportDir);

        parent::tearDown();
    }

    public function test_imports_published_photos_and_never_published_drafts(): void
    {
        $this->writeExport($this->standardDocuments());

        $this->artisan('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir])
            ->assertSuccessful();

        $this->assertSame(3, $this->family->photos()->count());

        $first = Photo::where('sanity_id', 'photo-1')->sole();
        $this->assertSame('2024-01-01', $first->data);
        $this->assertTrue($first->data_speciale);
        $this->assertFalse($first->is_draft, 'La versione pubblicata vince sulla bozza con lo stesso _id.');
        $this->assertSame('<p>Primo giorno di scuola<br>Zaino più grande di lui</p>', $first->didascalia);
        $this->assertSame([40, 30], [$first->width, $first->height]);
        $this->assertNull($first->uploaded_by);
        $this->assertSame("families/{$this->family->id}/photos/aaa111-40x30.jpg", $first->image_path);
        Storage::disk('r2')->assertExists($first->image_path);

        $second = Photo::where('sanity_id', 'photo-2')->sole();
        $this->assertFalse($second->data_speciale);
        $this->assertNull($second->didascalia);

        $draft = Photo::where('sanity_id', 'photo-3')->sole();
        $this->assertTrue($draft->is_draft);

        $expectedBytes = collect(File::files($this->exportDir.'/images'))
            ->filter(fn ($file) => $file->getFilename() !== 'aaa000-40x30.jpg')
            ->sum(fn ($file) => $file->getSize());
        $this->assertSame((int) $expectedBytes, (int) Photo::sum('size_bytes'));
        $this->assertSame(round($expectedBytes / 1024 / 1024, 2), $this->family->fresh()->storage_used_mb);
    }

    public function test_import_is_idempotent_and_updates_changed_metadata(): void
    {
        $documents = $this->standardDocuments();
        $this->writeExport($documents);

        $this->artisan('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir])->assertSuccessful();

        $documents[1]['special'] = true;
        $this->writeExport($documents);

        $this->artisan('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir.'/data.ndjson'])->assertSuccessful();

        $this->assertSame(3, $this->family->photos()->count());
        $this->assertTrue(Photo::where('sanity_id', 'photo-2')->sole()->data_speciale);
        // Tre originali più le rispettive miniature, senza duplicati.
        $this->assertCount(3, Storage::disk('r2')->files("families/{$this->family->id}/photos"));
        $this->assertCount(3, Storage::disk('r2')->files("families/{$this->family->id}/photos/thumbs"));
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->writeExport($this->standardDocuments());

        $this->artisan('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir, '--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_invalid_documents_are_reported_and_the_rest_is_imported(): void
    {
        $this->writeExport([
            ...$this->standardDocuments(),
            ['_id' => 'photo-4', '_type' => 'photo', 'data' => '2024-01-04', 'immagine' => $this->asset('mancante-10x10.jpg')],
            ['_id' => 'photo-5', '_type' => 'photo', 'data' => '04/01/2024', 'immagine' => $this->image('eee555-10x10.jpg')],
        ]);

        $exitCode = Artisan::call('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir]);
        $output = Artisan::output();

        $this->assertSame(Command::FAILURE, $exitCode);
        $this->assertStringContainsString('riga 7 (photo-4): file immagine non trovato: images/mancante-10x10.jpg', $output);
        $this->assertStringContainsString('riga 8 (photo-5): campo "data" mancante o non nel formato YYYY-MM-DD', $output);
        $this->assertSame(3, $this->family->photos()->count());
    }

    public function test_import_is_blocked_before_uploading_if_it_exceeds_the_plan(): void
    {
        $this->family->update(['plan_id' => Plan::factory()->limited(photos: 2)->create()->id]);
        $this->writeExport($this->standardDocuments());

        $this->artisan('photos:import', ['family' => 'giopellino', 'path' => $this->exportDir])
            ->expectsOutputToContain('Import bloccato dai limiti del piano')
            ->assertFailed();

        $this->assertSame(0, Photo::count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_unknown_family_fails(): void
    {
        $this->writeExport($this->standardDocuments());

        $this->artisan('photos:import', ['family' => 'sconosciuta', 'path' => $this->exportDir])->assertFailed();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function standardDocuments(): array
    {
        return [
            [
                '_id' => 'photo-1',
                '_type' => 'photo',
                'data' => '2024-01-01',
                'special' => true,
                'dida' => [
                    ['_type' => 'block', 'children' => [
                        ['_type' => 'span', 'text' => 'Primo giorno '],
                        ['_type' => 'span', 'text' => 'di scuola'],
                    ]],
                    ['_type' => 'block', 'children' => [['_type' => 'span', 'text' => 'Zaino più grande di lui']]],
                ],
                'immagine' => $this->image('aaa111-40x30.jpg', 40, 30),
            ],
            [
                '_id' => 'photo-2',
                '_type' => 'photo',
                'data' => '2024-01-02',
                'special' => false,
                'immagine' => $this->image('bbb222-30x40.jpg', 30, 40),
            ],
            [
                '_id' => 'drafts.photo-3',
                '_type' => 'photo',
                'data' => '2024-01-03',
                'immagine' => $this->image('ccc333-20x20.jpg'),
            ],
            // Bozza di un documento già pubblicato: va ignorata.
            [
                '_id' => 'drafts.photo-1',
                '_type' => 'photo',
                'data' => '2024-01-01',
                'immagine' => $this->image('aaa000-40x30.jpg', 40, 30),
            ],
            // Documenti di altri tipi presenti nell'export.
            ['_id' => 'image-aaa111-40x30-jpg', '_type' => 'sanity.imageAsset'],
            ['_id' => '_.groups.public', '_type' => 'system.group'],
        ];
    }

    /**
     * Crea un JPEG reale nella cartella images/ dell'export e restituisce il campo immagine Sanity.
     *
     * @return array<string, string>
     */
    private function image(string $filename, int $width = 20, int $height = 20): array
    {
        $image = imagecreatetruecolor($width, $height);
        imagejpeg($image, "{$this->exportDir}/images/{$filename}");
        imagedestroy($image);

        return $this->asset($filename);
    }

    /**
     * @return array<string, string>
     */
    private function asset(string $filename): array
    {
        return ['_type' => 'image', '_sanityAsset' => "image@file://./images/{$filename}"];
    }

    /**
     * @param  list<array<string, mixed>>  $documents
     */
    private function writeExport(array $documents): void
    {
        File::put(
            $this->exportDir.'/data.ndjson',
            collect($documents)->map(fn (array $document) => json_encode($document, JSON_UNESCAPED_UNICODE))->implode("\n")."\n",
        );
    }
}
