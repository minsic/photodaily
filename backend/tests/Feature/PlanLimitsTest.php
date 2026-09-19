<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Photo;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlanLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    public function test_new_families_get_the_beta_plan_without_limits(): void
    {
        $family = Family::factory()->create();

        $this->assertSame('beta', $family->plan->slug);
        $this->assertNull($family->plan->max_photos);
        $this->assertNull($family->plan->max_storage_mb);
    }

    public function test_upload_is_blocked_when_the_photo_limit_is_reached(): void
    {
        $user = $this->memberOfFamilyWithPlan(Plan::factory()->limited(photos: 2)->create(['name' => 'Free']));
        Photo::factory()->for($user->family)->count(2)->create();

        Sanctum::actingAs($user);

        $this->postJson('/api/photos', $this->payload())
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_limit_exceeded')
            ->assertJsonPath('limit', 'max_photos')
            ->assertJsonPath('message', 'Hai raggiunto il limite di 2 foto previsto dal piano "Free". Elimina qualche foto o passa a un piano superiore per caricarne altre.');

        $this->assertSame(2, $user->family->photos()->count());
        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_upload_is_blocked_when_it_would_exceed_the_storage_limit(): void
    {
        $user = $this->memberOfFamilyWithPlan(Plan::factory()->limited(storageMb: 1)->create());
        Photo::factory()->for($user->family)->create(['size_bytes' => 900 * 1024]);

        Sanctum::actingAs($user);

        $this->postJson('/api/photos', $this->payload(sizeKb: 200))
            ->assertForbidden()
            ->assertJsonPath('code', 'plan_limit_exceeded')
            ->assertJsonPath('limit', 'max_storage_mb');

        $this->assertSame([], Storage::disk('r2')->allFiles());
    }

    public function test_upload_within_the_limits_is_allowed(): void
    {
        $user = $this->memberOfFamilyWithPlan(Plan::factory()->limited(photos: 2, storageMb: 1)->create());

        Sanctum::actingAs($user);

        $this->postJson('/api/photos', $this->payload(sizeKb: 200))->assertCreated();
    }

    public function test_storage_used_is_updated_on_upload_and_delete_with_the_real_file_size(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $first = UploadedFile::fake()->image('a.jpg')->size(1536);
        $second = UploadedFile::fake()->image('b.jpg')->size(512);

        $this->postJson('/api/photos', ['image' => $first, 'data' => '2024-01-01'])->assertCreated();
        $secondId = $this->postJson('/api/photos', ['image' => $second, 'data' => '2024-01-02'])->assertCreated()->json('data.id');

        $this->assertSame(2.0, $user->family->fresh()->storage_used_mb);

        $this->deleteJson("/api/photos/{$secondId}")->assertNoContent();

        $this->assertSame(1.5, $user->family->fresh()->storage_used_mb);

        $this->getJson('/api/me')
            ->assertJsonPath('data.family.storage_used_mb', 1.5)
            ->assertJsonPath('data.family.photos_count', 1)
            ->assertJsonPath('data.family.plan.slug', 'beta');
    }

    private function memberOfFamilyWithPlan(Plan $plan): User
    {
        return User::factory()->for(Family::factory()->for($plan))->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(int $sizeKb = 100): array
    {
        return [
            'image' => UploadedFile::fake()->image('foto.jpg')->size($sizeKb),
            'data' => '2024-05-01',
        ];
    }
}
