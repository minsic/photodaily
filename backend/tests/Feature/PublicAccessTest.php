<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Services\FamilyReadTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PublicAccessTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'password-di-famiglia';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
    }

    public function test_private_family_is_invisible_from_the_public_routes(): void
    {
        $family = $this->family(AccessMode::Private);
        $photo = Photo::factory()->for($family)->create();

        $onPrivate = $this->getJson('/api/public/giopellino/photos')->assertNotFound();
        $unknown = $this->getJson('/api/public/sconosciuta/photos')->assertNotFound();

        $this->assertSame(
            $unknown->json('message'),
            $onPrivate->json('message'),
            'Una famiglia privata deve rispondere come una famiglia inesistente.',
        );

        $this->getJson("/api/public/giopellino/photos/{$photo->id}")->assertNotFound();
        $this->postJson('/api/public/giopellino/verify-password', ['password' => self::PASSWORD])->assertNotFound();
    }

    public function test_public_family_photos_are_readable_without_an_account(): void
    {
        $family = $this->family(AccessMode::Public);
        $first = Photo::factory()->for($family)->create(['data' => '2024-05-01']);
        $second = Photo::factory()->for($family)->special()->create(['data' => '2024-05-02']);
        $otherFamilyPhoto = Photo::factory()->create(['data' => '2024-05-03']);

        $response = $this->getJson('/api/public/giopellino/photos')->assertOk();

        $this->assertSame([$second->id, $first->id], $response->json('data.*.id'));
        $this->assertNotEmpty($response->json('data.0.thumbnail_url'));
        $this->assertArrayNotHasKey('uploaded_by', $response->json('data.0'));

        $this->getJson('/api/public/giopellino/photos?anno=2024&speciali=1')
            ->assertJsonPath('data.*.id', [$second->id]);

        $this->getJson("/api/public/giopellino/photos/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $first->id)
            ->assertJsonPath('data.successiva.id', $second->id)
            ->assertJsonPath('data.precedente', null);

        $this->getJson("/api/public/giopellino/photos/{$otherFamilyPhoto->id}")->assertNotFound();
    }

    #[DataProvider('publicReadableModes')]
    public function test_drafts_are_never_visible_publicly(string $mode): void
    {
        $family = $this->family(AccessMode::from($mode));
        $published = Photo::factory()->for($family)->create(['data' => '2024-05-01']);
        $draft = Photo::factory()->for($family)->draft()->create(['data' => '2024-05-02']);
        $headers = $this->readTokenHeaders($family);

        foreach (['', '?stato=tutte', '?stato=bozze'] as $query) {
            $this->getJson("/api/public/giopellino/photos{$query}", $headers)
                ->assertOk()
                ->assertJsonPath('data.*.id', [$published->id]);
        }

        $this->getJson("/api/public/giopellino/photos/{$draft->id}", $headers)->assertNotFound();

        // La bozza non compare nemmeno nella navigazione.
        $this->getJson("/api/public/giopellino/photos/{$published->id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.successiva', null);
    }

    /**
     * @return list<array{string}>
     */
    public static function publicReadableModes(): array
    {
        return [['public'], ['password']];
    }

    public function test_public_routes_expose_no_way_to_write(): void
    {
        $family = $this->family(AccessMode::Public);
        $photo = Photo::factory()->for($family)->create();

        $this->postJson('/api/public/giopellino/photos', ['data' => '2024-05-01'])->assertMethodNotAllowed();
        $this->patchJson("/api/public/giopellino/photos/{$photo->id}", ['didascalia' => 'x'])->assertMethodNotAllowed();
        $this->deleteJson("/api/public/giopellino/photos/{$photo->id}")->assertMethodNotAllowed();

        // Le rotte di scrittura restano quelle autenticate.
        $this->postJson('/api/photos', ['data' => '2024-05-01'])->assertUnauthorized();
        $this->patchJson("/api/photos/{$photo->id}", ['didascalia' => 'x'])->assertUnauthorized();
        $this->deleteJson("/api/photos/{$photo->id}")->assertUnauthorized();

        $this->assertNotSame('x', $photo->fresh()->didascalia, 'La didascalia non deve essere cambiata.');
        $this->assertModelExists($photo);
    }

    public function test_password_family_requires_the_shared_password(): void
    {
        $family = $this->family(AccessMode::Password);
        $photo = Photo::factory()->for($family)->create();

        $this->getJson('/api/public/giopellino/photos')->assertUnauthorized();
        $this->getJson("/api/public/giopellino/photos/{$photo->id}")->assertUnauthorized();
        $this->getJson('/api/public/giopellino/photos', ['Authorization' => 'Bearer non-valido'])->assertUnauthorized();

        $this->postJson('/api/public/giopellino/verify-password', ['password' => 'sbagliata'])->assertUnauthorized();

        $verified = $this->postJson('/api/public/giopellino/verify-password', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('family.name', $family->name);

        $token = $verified->json('token');
        $this->assertNotEmpty($token);
        $this->assertNotEmpty($verified->json('expires_at'));

        $this->getJson('/api/public/giopellino/photos', ['Authorization' => "Bearer {$token}"])
            ->assertOk()
            ->assertJsonPath('data.*.id', [$photo->id]);

        $this->getJson("/api/public/giopellino/photos?access_token={$token}")
            ->assertOk()
            ->assertJsonPath('data.*.id', [$photo->id]);

        $this->getJson("/api/public/giopellino/photos/{$photo->id}?access_token={$token}")->assertOk();
    }

    public function test_read_token_works_only_for_its_own_family(): void
    {
        $family = $this->family(AccessMode::Password);
        $other = Family::factory()->create([
            'slug' => 'altra',
            'access_mode' => AccessMode::Password,
            'access_password_hash' => Hash::make(self::PASSWORD),
        ]);
        Photo::factory()->for($other)->create();

        $token = $this->readToken($family);

        $this->getJson('/api/public/altra/photos', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_read_token_expires(): void
    {
        $family = $this->family(AccessMode::Password);
        Photo::factory()->for($family)->create();
        $token = $this->readToken($family);

        $this->travel(6)->days();
        $this->getJson('/api/public/giopellino/photos', ['Authorization' => "Bearer {$token}"])->assertOk();

        $this->travel(2)->days();
        $this->getJson('/api/public/giopellino/photos', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();
    }

    public function test_read_token_is_not_accepted_on_authenticated_routes(): void
    {
        $family = $this->family(AccessMode::Public);
        $photo = Photo::factory()->for($family)->create();
        $family->changeAccessMode(AccessMode::Password, self::PASSWORD);
        $token = $this->readToken($family->fresh());

        $this->withToken($token)->getJson('/api/me')->assertUnauthorized();
        $this->withToken($token)->getJson('/api/photos')->assertUnauthorized();
        $this->withToken($token)->getJson("/api/photos/{$photo->id}")->assertUnauthorized();
        $this->withToken($token)->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg'),
            'data' => '2024-05-01',
        ])->assertUnauthorized();
        $this->withToken($token)->patchJson("/api/photos/{$photo->id}", ['didascalia' => 'x'])->assertUnauthorized();
        $this->withToken($token)->deleteJson("/api/photos/{$photo->id}")->assertUnauthorized();
        $this->withToken($token)->postJson('/api/invites', ['email' => 'zia@example.com'])->assertUnauthorized();
        $this->withToken($token)->patchJson('/api/family/access-mode', ['access_mode' => 'public'])->assertUnauthorized();

        $this->assertDatabaseCount('photos', 1);
        $this->assertSame(AccessMode::Password, $family->fresh()->access_mode);
    }

    public function test_admin_can_change_the_access_mode(): void
    {
        $family = $this->family(AccessMode::Private);
        $admin = User::factory()->admin()->for($family)->create();
        Photo::factory()->for($family)->create();

        Sanctum::actingAs($admin);

        $response = $this->patchJson('/api/family/access-mode', ['access_mode' => 'public'])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'public');

        $this->assertArrayNotHasKey('access_password_hash', $response->json('data'));
        $this->assertSame(AccessMode::Public, $family->fresh()->access_mode);

        $this->getJson('/api/public/giopellino/photos')->assertOk();

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'password', 'password' => 'nuova-password'])
            ->assertOk()
            ->assertJsonPath('data.access_mode', 'password');

        $family->refresh();
        $this->assertNotNull($family->access_password_hash);
        $this->assertTrue(Hash::check('nuova-password', $family->access_password_hash));

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'private'])->assertOk();

        $family->refresh();
        $this->assertSame(AccessMode::Private, $family->access_mode);
        $this->assertNull($family->access_password_hash, 'Uscendo dalla modalità password l\'hash va azzerato.');
    }

    public function test_changing_password_or_mode_invalidates_the_tokens_already_issued(): void
    {
        $family = $this->family(AccessMode::Password);
        Photo::factory()->for($family)->create();
        $token = $this->readToken($family);
        $admin = User::factory()->admin()->for($family)->create();

        Sanctum::actingAs($admin);
        $this->patchJson('/api/family/access-mode', ['access_mode' => 'password', 'password' => 'un-altra-password'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/giopellino/photos', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

        // Anche tornando alla password originale, i vecchi token restano invalidi.
        Sanctum::actingAs($admin);
        $this->patchJson('/api/family/access-mode', ['access_mode' => 'password', 'password' => self::PASSWORD])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/public/giopellino/photos', ['Authorization' => "Bearer {$token}"])->assertUnauthorized();

        $this->postJson('/api/public/giopellino/verify-password', ['password' => self::PASSWORD])->assertOk();
    }

    public function test_only_an_admin_can_change_the_access_mode(): void
    {
        $family = $this->family(AccessMode::Private);
        $member = User::factory()->for($family)->create();

        Sanctum::actingAs($member);

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'public'])->assertForbidden();

        $this->assertSame(AccessMode::Private, $family->fresh()->access_mode);
        $this->getJson('/api/public/giopellino/photos')->assertNotFound();
    }

    public function test_access_mode_payload_is_validated(): void
    {
        $family = $this->family(AccessMode::Private);
        Sanctum::actingAs(User::factory()->admin()->for($family)->create());

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'password'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'password', 'password' => 'corta'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        $this->patchJson('/api/family/access-mode', ['access_mode' => 'aperta-a-tutti'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('access_mode');

        $this->assertSame(AccessMode::Private, $family->fresh()->access_mode);
    }

    public function test_password_verification_is_rate_limited(): void
    {
        $this->family(AccessMode::Password);

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/public/giopellino/verify-password', ['password' => 'sbagliata'])->assertUnauthorized();
        }

        $this->postJson('/api/public/giopellino/verify-password', ['password' => self::PASSWORD])->assertTooManyRequests();
    }

    private function family(AccessMode $mode): Family
    {
        return Family::factory()->create([
            'slug' => 'giopellino',
            'access_mode' => $mode,
            'access_password_hash' => $mode === AccessMode::Password ? Hash::make(self::PASSWORD) : null,
        ]);
    }

    private function readToken(Family $family): string
    {
        [$token] = app(FamilyReadTokens::class)->issue($family);

        return $token;
    }

    /**
     * @return array<string, string>
     */
    private function readTokenHeaders(Family $family): array
    {
        return $family->access_mode === AccessMode::Password
            ? ['Authorization' => 'Bearer '.$this->readToken($family)]
            : [];
    }
}
