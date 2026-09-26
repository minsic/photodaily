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

class PhotoCrudTest extends TestCase
{
    use RefreshDatabase;

    private Family $family;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        $this->family = Family::factory()->create();
        $this->user = User::factory()->for($this->family)->create();

        Sanctum::actingAs($this->user);
    }

    public function test_member_can_upload_a_photo_to_r2(): void
    {
        $image = UploadedFile::fake()->image('foto.jpg', 800, 600)->size(300);

        $response = $this->postJson('/api/photos', [
            'image' => $image,
            'data' => '2024-05-01',
            'didascalia' => 'Primo giorno al mare',
            'data_speciale' => 'true',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.data', '2024-05-01')
            ->assertJsonPath('data.didascalia', '<p>Primo giorno al mare</p>')
            ->assertJsonPath('data.data_speciale', true)
            ->assertJsonPath('data.is_draft', false)
            ->assertJsonPath('data.width', 800)
            ->assertJsonPath('data.height', 600)
            ->assertJsonPath('data.uploaded_by', $this->user->id);

        $photo = Photo::where('ulid', $response->json('data.id'))->firstOrFail();

        Storage::disk('r2')->assertExists($photo->image_path);
        $this->assertSame($image->getSize(), $photo->size_bytes);
        $this->assertNotEmpty($response->json('data.image_url'));
    }

    public function test_upload_is_validated(): void
    {
        $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->create('documento.pdf', 10, 'application/pdf'),
            'data' => '01/05/2024',
        ])->assertUnprocessable()->assertJsonValidationErrors(['image', 'data']);
    }

    public function test_index_hides_drafts_by_default_and_filters_by_year_and_special(): void
    {
        $published2023 = Photo::factory()->for($this->family)->create(['data' => '2023-12-31']);
        $special2024 = Photo::factory()->for($this->family)->special()->create(['data' => '2024-01-01']);
        $plain2024 = Photo::factory()->for($this->family)->create(['data' => '2024-02-01']);
        $draft = Photo::factory()->for($this->family)->draft()->create(['data' => '2024-03-01']);

        $this->getJson('/api/photos')
            ->assertOk()
            ->assertJsonPath('data.*.id', [$plain2024->ulid, $special2024->ulid, $published2023->ulid]);

        $this->getJson('/api/photos?anno=2024')
            ->assertJsonPath('data.*.id', [$plain2024->ulid, $special2024->ulid]);

        $this->getJson('/api/photos?speciali=1')
            ->assertJsonPath('data.*.id', [$special2024->ulid]);

        $this->getJson('/api/photos?stato=bozze')
            ->assertJsonPath('data.*.id', [$draft->ulid]);

        $this->getJson('/api/photos?ordine=asc&per_page=2')
            ->assertJsonPath('data.*.id', [$published2023->ulid, $special2024->ulid])
            ->assertJsonPath('meta.total', 3);
    }

    public function test_show_includes_previous_and_next_photo(): void
    {
        $previous = Photo::factory()->for($this->family)->create(['data' => '2024-05-01']);
        $photo = Photo::factory()->for($this->family)->create(['data' => '2024-05-02']);
        $next = Photo::factory()->for($this->family)->create(['data' => '2024-05-03']);
        Photo::factory()->for($this->family)->draft()->create(['data' => '2024-05-04']);

        $this->getJson("/api/photos/{$photo->ulid}")
            ->assertOk()
            ->assertJsonPath('data.precedente.id', $previous->ulid)
            ->assertJsonPath('data.precedente.data', '2024-05-01')
            ->assertJsonPath('data.successiva.id', $next->ulid)
            ->assertJsonPath('data.successiva.data', '2024-05-03')
            // Per precaricarle nel lettore senza altre richieste.
            ->assertJsonPath('data.successiva.thumbnail_url', fn (string $url) => str_contains($url, $next->thumbnail_path ?? $next->image_path))
            ->assertJsonStructure(['data' => ['precedente' => ['medium_url', 'medium_width', 'medium_height']]]);

        $this->getJson("/api/photos/{$next->ulid}")
            ->assertJsonPath('data.successiva', null);
    }

    public function test_member_can_update_photo_metadata(): void
    {
        $photo = Photo::factory()->for($this->family)->draft()->create(['data' => '2024-05-01']);

        $this->patchJson("/api/photos/{$photo->ulid}", [
            'data' => '2024-05-02',
            'didascalia' => 'Nuova didascalia',
            'data_speciale' => true,
            'is_draft' => false,
        ])->assertOk()
            ->assertJsonPath('data.data', '2024-05-02')
            ->assertJsonPath('data.didascalia', '<p>Nuova didascalia</p>')
            ->assertJsonPath('data.data_speciale', true)
            ->assertJsonPath('data.is_draft', false);
    }

    public function test_deleting_a_photo_keeps_an_image_still_used_by_another_photo(): void
    {
        // Capita con l'import: due documenti Sanity che puntano allo stesso asset.
        $shared = "families/{$this->family->id}/photos/condivisa.jpg";
        Storage::disk('r2')->put($shared, 'contenuto');

        $first = Photo::factory()->for($this->family)->create(['image_path' => $shared, 'data' => '2024-05-01']);
        $second = Photo::factory()->for($this->family)->create(['image_path' => $shared, 'data' => '2024-05-02']);

        $this->deleteJson("/api/photos/{$first->ulid}")->assertNoContent();

        Storage::disk('r2')->assertExists($shared);

        $this->deleteJson("/api/photos/{$second->ulid}")->assertNoContent();

        Storage::disk('r2')->assertMissing($shared);
    }

    public function test_years_endpoint_lists_only_years_with_published_photos_of_the_family(): void
    {
        Photo::factory()->for($this->family)->create(['data' => '2023-12-31']);
        Photo::factory()->for($this->family)->special()->create(['data' => '2024-01-01']);
        Photo::factory()->for($this->family)->create(['data' => '2024-02-01']);
        Photo::factory()->for($this->family)->draft()->create(['data' => '2022-01-01']);
        Photo::factory()->create(['data' => '2019-01-01']);

        $this->getJson('/api/photos/anni')
            ->assertOk()
            ->assertExactJson(['data' => [
                ['anno' => 2024, 'foto' => 2, 'speciali' => 1],
                ['anno' => 2023, 'foto' => 1, 'speciali' => 0],
            ]]);
    }

    public function test_member_can_delete_a_photo_and_its_file(): void
    {
        $response = $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg')->size(500),
            'data' => '2024-05-01',
        ])->assertCreated();

        $photo = Photo::where('ulid', $response->json('data.id'))->firstOrFail();

        $this->deleteJson("/api/photos/{$photo->ulid}")->assertNoContent();

        $this->assertModelMissing($photo);
        Storage::disk('r2')->assertMissing($photo->image_path);
    }
}
