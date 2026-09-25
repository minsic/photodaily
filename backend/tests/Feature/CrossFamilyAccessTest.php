<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Invite;
use App\Models\Photo;
use App\Models\User;
use App\Services\FamilyReadTokens;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Un utente della famiglia A non deve poter leggere, modificare o anche solo
 * scoprire l'esistenza di foto, inviti e impostazioni della famiglia B,
 * qualunque sia la modalità di accesso di B e da qualunque indirizzo arrivi.
 *
 * Gli ID sono numeri in sequenza, quindi facili da indovinare: ogni risposta
 * su una risorsa di B deve essere identica a quella su un ID inesistente,
 * così nessuno stato o messaggio fa da oracolo.
 */
class CrossFamilyAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MISSING_ID = 999999;

    private Family $familyA;

    private Family $familyB;

    private User $adminA;

    private Photo $photoB;

    private Invite $inviteB;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('r2');
        config(['photodaily.frontend_url' => 'https://photodaily.app']);

        $this->familyA = Family::factory()->create(['slug' => 'famiglia-a']);
        $this->familyB = Family::factory()->create(['slug' => 'famiglia-b', 'custom_domain' => 'famiglia-b.it']);

        $this->adminA = User::factory()->admin()->for($this->familyA)->create();
        $adminB = User::factory()->admin()->for($this->familyB)->create();

        Photo::factory()->for($this->familyA)->create(['data' => '2024-05-01']);
        $this->photoB = Photo::factory()->for($this->familyB)->create([
            'data' => '2024-05-02',
            'didascalia' => '<p>Originale di B</p>',
        ]);
        $this->inviteB = Invite::factory()->for($this->familyB)->create([
            'email' => 'invitata-da-b@example.com',
            'invited_by' => $adminB->id,
        ]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accessModes(): array
    {
        return [
            'B privata' => ['private'],
            'B con password' => ['password'],
            'B pubblica' => ['public'],
        ];
    }

    /**
     * Da dove può arrivare la richiesta di A: l'indirizzo principale, il suo
     * sottodominio, o un host qualsiasi (per esempio l'IP del server).
     *
     * @return array<string, array{string}>
     */
    public static function neutralOrOwnHosts(): array
    {
        return [
            'indirizzo principale' => ['https://photodaily.app'],
            'indirizzo di A' => ['https://famiglia-a.photodaily.app'],
            'host sconosciuto' => ['http://localhost'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function modesAndHosts(): array
    {
        $cases = [];

        foreach (self::accessModes() as $modeLabel => [$mode]) {
            foreach (self::neutralOrOwnHosts() as $hostLabel => [$host]) {
                $cases["{$modeLabel}, da {$hostLabel}"] = [$mode, $host];
            }
        }

        return $cases;
    }

    #[DataProvider('modesAndHosts')]
    public function test_photos_of_another_family_answer_exactly_like_missing_ones(string $mode, string $host): void
    {
        $this->setModeOfB($mode);
        Sanctum::actingAs($this->adminA);

        $payload = ['data' => '2020-01-01', 'didascalia' => 'Modificata da A', 'data_speciale' => true];

        foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->assertSameAsMissing(
                $this->json($method, "{$host}/api/photos/{$this->photoB->id}", $payload),
                $this->json($method, "{$host}/api/photos/".self::MISSING_ID, $payload),
                "{$method} photos/{id}",
            );
        }

        $this->assertModelExists($this->photoB);
        $this->assertSame('<p>Originale di B</p>', $this->photoB->fresh()->didascalia);
        $this->assertSame('2024-05-02', $this->photoB->fresh()->data);
    }

    #[DataProvider('modesAndHosts')]
    public function test_invites_of_another_family_answer_exactly_like_missing_ones(string $mode, string $host): void
    {
        $this->setModeOfB($mode);
        Sanctum::actingAs($this->adminA);

        // Sugli inviti esiste solo DELETE: GET e PUT devono dare la stessa
        // risposta (405) per un invito di B e per uno che non esiste.
        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->assertSameAsMissing(
                $this->json($method, "{$host}/api/invites/{$this->inviteB->id}", ['email' => 'x@example.com']),
                $this->json($method, "{$host}/api/invites/".self::MISSING_ID, ['email' => 'x@example.com']),
                "{$method} invites/{id}",
            );
        }

        $this->assertModelExists($this->inviteB);

        // E l'elenco degli inviti di A non contiene quelli di B.
        $this->getJson("{$host}/api/invites")
            ->assertOk()
            ->assertJsonMissing(['email' => 'invitata-da-b@example.com']);
    }

    #[DataProvider('modesAndHosts')]
    public function test_access_mode_changes_only_ever_touch_the_users_own_family(string $mode, string $host): void
    {
        $this->setModeOfB($mode);
        $hashB = $this->familyB->fresh()->access_password_hash;
        Sanctum::actingAs($this->adminA);

        // Non c'è un ID di famiglia nell'URL né nel payload che si possa
        // manipolare: un family_id in più viene ignorato.
        $this->patchJson("{$host}/api/family/access-mode", [
            'access_mode' => 'public',
            'family_id' => $this->familyB->id,
        ])->assertOk()->assertJsonPath('data.slug', 'famiglia-a');

        $this->assertSame(AccessMode::Public, $this->familyA->fresh()->access_mode);
        $this->assertSame(AccessMode::from($mode), $this->familyB->fresh()->access_mode);
        $this->assertSame($hashB, $this->familyB->fresh()->access_password_hash);

        foreach (['GET', 'PUT', 'DELETE'] as $method) {
            $this->json($method, "{$host}/api/family/access-mode")->assertStatus(405);
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostsOfB(): array
    {
        return [
            'sottodominio di B' => ['https://famiglia-b.photodaily.app'],
            'dominio proprio di B' => ['https://famiglia-b.it'],
            'www del dominio di B' => ['https://www.famiglia-b.it'],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function modesAndHostsOfB(): array
    {
        $cases = [];

        foreach (self::accessModes() as $modeLabel => [$mode]) {
            foreach (self::hostsOfB() as $hostLabel => [$host]) {
                $cases["{$modeLabel}, dal {$hostLabel}"] = [$mode, $host];
            }
        }

        return $cases;
    }

    #[DataProvider('modesAndHostsOfB')]
    public function test_on_the_address_of_family_b_every_authenticated_endpoint_is_not_found_for_a(string $mode, string $host): void
    {
        $this->setModeOfB($mode);
        Sanctum::actingAs($this->adminA);

        $requests = [
            ['GET', '/api/me'],
            ['GET', '/api/photos'],
            ['GET', '/api/photos/anni'],
            ['GET', "/api/photos/{$this->photoB->id}"],
            ['PUT', "/api/photos/{$this->photoB->id}"],
            ['DELETE', "/api/photos/{$this->photoB->id}"],
            ['POST', '/api/photos'],
            ['GET', '/api/invites'],
            ['POST', '/api/invites'],
            ['DELETE', "/api/invites/{$this->inviteB->id}"],
            ['PATCH', '/api/family/access-mode'],
        ];

        foreach ($requests as [$method, $uri]) {
            $this->json($method, $host.$uri, ['access_mode' => 'public', 'email' => 'x@example.com'])
                ->assertNotFound();
        }

        $this->assertModelExists($this->photoB);
        $this->assertModelExists($this->inviteB);
        $this->assertSame(AccessMode::from($mode), $this->familyB->fresh()->access_mode);
        $this->assertSame(AccessMode::Private, $this->familyA->fresh()->access_mode);
    }

    #[DataProvider('accessModes')]
    public function test_public_routes_of_a_never_show_photos_of_b(string $mode): void
    {
        $this->setModeOfB($mode);
        $this->familyA->changeAccessMode(AccessMode::Public);

        $this->assertSameAsMissing(
            $this->getJson("/api/public/famiglia-a/photos/{$this->photoB->id}"),
            $this->getJson('/api/public/famiglia-a/photos/'.self::MISSING_ID),
            'GET public/A/photos/{id di B}',
        );

        $this->getJson('/api/public/famiglia-a/photos')
            ->assertOk()
            ->assertJsonMissing(['id' => $this->photoB->id]);
    }

    #[DataProvider('accessModes')]
    public function test_the_read_token_of_a_opens_nothing_of_b(string $mode): void
    {
        $this->setModeOfB($mode);
        $this->familyA->changeAccessMode(AccessMode::Password, 'password-di-a');
        [$tokenA] = app(FamilyReadTokens::class)->issue($this->familyA->fresh());

        $response = $this->withToken($tokenA)->getJson("/api/public/famiglia-b/photos/{$this->photoB->id}");

        match ($mode) {
            // Privata: la stessa risposta di una famiglia inesistente.
            'private' => $response->assertNotFound(),
            // Con password: il token di A non vale per B.
            'password' => $response->assertUnauthorized(),
            // Pubblica: la foto è visibile a chiunque, token o no. Non è una falla.
            'public' => $response->assertOk(),
        };

        // Il token di sola lettura non apre in nessun caso le rotte autenticate.
        $this->withToken($tokenA)->getJson("/api/photos/{$this->photoB->id}")->assertUnauthorized();
    }

    private function setModeOfB(string $mode): void
    {
        $this->familyB->changeAccessMode(
            AccessMode::from($mode),
            $mode === 'password' ? 'password-di-b' : null,
        );
    }

    /**
     * Stesso stato e stesso corpo: nemmeno il messaggio deve distinguere
     * una risorsa di un'altra famiglia da una che non esiste.
     */
    private function assertSameAsMissing(TestResponse $other, TestResponse $missing, string $what): void
    {
        $this->assertContains($missing->status(), [404, 405], "{$what}: su un ID inesistente ci si aspetta 404 o 405.");
        $this->assertSame($missing->status(), $other->status(), "{$what}: lo stato rivela che la risorsa esiste.");
        // Il 405 ripete l'URL richiesto: si confronta il messaggio a meno dell'ID.
        $withoutId = fn (TestResponse $response) => preg_replace('~/\d+\b~', '/{id}', (string) $response->json('message'));

        $this->assertSame(
            $withoutId($missing),
            $withoutId($other),
            "{$what}: il messaggio rivela che la risorsa esiste.",
        );
    }
}
