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

/**
 * Un utente della famiglia A non deve mai poter leggere o modificare le foto della famiglia B.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Family $familyA;

    private Family $familyB;

    private User $userA;

    private Photo $photoB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');

        $this->familyA = Family::factory()->create();
        $this->familyB = Family::factory()->create();
        $this->userA = User::factory()->admin()->for($this->familyA)->create();

        $this->photoB = Photo::factory()->for($this->familyB)->create(['data' => '2024-05-02']);
        Storage::disk('r2')->put($this->photoB->image_path, 'contenuto');
    }

    public function test_guests_cannot_access_any_photo_endpoint(): void
    {
        $this->getJson('/api/photos')->assertUnauthorized();
        $this->getJson("/api/photos/{$this->photoB->id}")->assertUnauthorized();
        $this->postJson('/api/photos', [])->assertUnauthorized();
        $this->patchJson("/api/photos/{$this->photoB->id}", ['didascalia' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/photos/{$this->photoB->id}")->assertUnauthorized();
    }

    public function test_index_lists_only_photos_of_the_users_family(): void
    {
        $own = Photo::factory()->for($this->familyA)->count(2)->create();

        Sanctum::actingAs($this->userA);

        $response = $this->getJson('/api/photos?stato=tutte')->assertOk();

        $this->assertEqualsCanonicalizing($own->pluck('id')->all(), $response->json('data.*.id'));
        $response->assertJsonMissing(['id' => $this->photoB->id]);
    }

    public function test_user_cannot_read_a_photo_of_another_family(): void
    {
        Sanctum::actingAs($this->userA);

        $this->getJson("/api/photos/{$this->photoB->id}")->assertNotFound();
    }

    public function test_user_cannot_update_a_photo_of_another_family(): void
    {
        Sanctum::actingAs($this->userA);

        $this->patchJson("/api/photos/{$this->photoB->id}", ['didascalia' => 'modificata'])->assertNotFound();

        $this->assertNotSame('modificata', $this->photoB->fresh()->didascalia);
    }

    public function test_invalid_update_to_another_familys_photo_returns_404_not_validation_errors(): void
    {
        Sanctum::actingAs($this->userA);

        $this->patchJson("/api/photos/{$this->photoB->id}", ['data' => 'non-una-data'])->assertNotFound();
    }

    public function test_user_cannot_delete_a_photo_of_another_family(): void
    {
        Sanctum::actingAs($this->userA);

        $this->deleteJson("/api/photos/{$this->photoB->id}")->assertNotFound();

        $this->assertModelExists($this->photoB);
        Storage::disk('r2')->assertExists($this->photoB->image_path);
    }

    public function test_navigation_never_links_to_another_familys_photo(): void
    {
        $first = Photo::factory()->for($this->familyA)->create(['data' => '2024-05-01']);
        $third = Photo::factory()->for($this->familyA)->create(['data' => '2024-05-03']);

        Sanctum::actingAs($this->userA);

        $this->getJson("/api/photos/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.precedente', null)
            ->assertJsonPath('data.successiva.id', $third->id);

        $this->getJson("/api/photos/{$third->id}")
            ->assertOk()
            ->assertJsonPath('data.precedente.id', $first->id)
            ->assertJsonPath('data.successiva', null);
    }

    public function test_uploads_always_go_to_the_users_family_whatever_the_payload_says(): void
    {
        Sanctum::actingAs($this->userA);

        $response = $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg'),
            'data' => '2024-06-01',
            'family_id' => $this->familyB->id,
        ])->assertCreated();

        $photo = Photo::findOrFail($response->json('data.id'));

        $this->assertSame($this->familyA->id, $photo->family_id);
        $this->assertStringStartsWith("families/{$this->familyA->id}/", $photo->image_path);
    }

    public function test_family_b_users_see_only_their_own_photos(): void
    {
        Photo::factory()->for($this->familyA)->count(3)->create();
        $userB = User::factory()->for($this->familyB)->create();

        Sanctum::actingAs($userB);

        $this->getJson('/api/photos')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->photoB->id);
    }
}
