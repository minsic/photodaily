<?php

namespace Tests\Feature;

use App\Enums\AccessMode;
use App\Models\Family;
use App\Models\Invite;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Facades\Artisan;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class HostFamilyTest extends TestCase
{
    use RefreshDatabase;

    private Family $giopellino;

    private Family $rossi;

    protected function tearDown(): void
    {
        // TrustProxies::at() è statico: non deve sopravvivere a questo test.
        TrustProxies::flushState();

        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();

        config(['photodaily.frontend_url' => 'https://photodaily.app']);

        $this->giopellino = Family::factory()->create(['slug' => 'giopellino', 'custom_domain' => 'giopellino.it']);
        $this->rossi = Family::factory()->create(['slug' => 'rossi']);
    }

    public function test_family_is_resolved_from_subdomain_and_custom_domain(): void
    {
        $this->assertTrue($this->giopellino->is(Family::forHost('giopellino.photodaily.app')));
        $this->assertTrue($this->giopellino->is(Family::forHost('GIOPELLINO.photodaily.app.')));
        $this->assertTrue($this->giopellino->is(Family::forHost('giopellino.it')));
        $this->assertTrue($this->giopellino->is(Family::forHost('www.giopellino.it:443')));
        $this->assertTrue($this->rossi->is(Family::forHost('rossi.photodaily.app')));
    }

    public function test_unknown_hosts_and_the_main_host_have_no_family(): void
    {
        $this->assertNull(Family::forHost('photodaily.app'));
        $this->assertNull(Family::forHost('www.photodaily.app'));
        $this->assertNull(Family::forHost('nessuno.photodaily.app'));
        $this->assertNull(Family::forHost('a.giopellino.photodaily.app'));
        $this->assertNull(Family::forHost('rossi.it'));
        $this->assertNull(Family::forHost('giopellino.photodaily.app.evil.com'));
        $this->assertNull(Family::forHost(''));
    }

    public function test_family_url_prefers_the_custom_domain(): void
    {
        $this->assertSame('https://giopellino.it', $this->giopellino->url());
        $this->assertSame('https://rossi.photodaily.app', $this->rossi->url());

        config(['photodaily.frontend_url' => 'http://localhost:5173']);

        $this->assertSame('http://rossi.localhost:5173', $this->rossi->url());
    }

    public function test_site_endpoint_describes_the_family_of_the_host(): void
    {
        $this->giopellino->changeAccessMode(AccessMode::Public);

        $this->getJson('https://giopellino.photodaily.app/api/site')
            ->assertOk()
            ->assertExactJson(['data' => ['family' => [
                'name' => $this->giopellino->name,
                'slug' => 'giopellino',
                'access_mode' => 'public',
            ]]]);

        $this->getJson('https://www.giopellino.it/api/site')
            ->assertOk()
            ->assertJsonPath('data.family.slug', 'giopellino');
    }

    public function test_site_endpoint_on_main_host_has_no_family_and_unknown_hosts_are_not_found(): void
    {
        $this->getJson('https://photodaily.app/api/site')
            ->assertOk()
            ->assertExactJson(['data' => ['family' => null]]);

        $this->getJson('https://nessuno.photodaily.app/api/site')->assertNotFound();
        $this->getJson('https://example.com/api/site')->assertNotFound();
    }

    public function test_login_on_a_family_host_only_accepts_its_members(): void
    {
        User::factory()->for($this->giopellino)->create(['email' => 'nonna@example.com', 'password' => 'password-lunga']);
        $credentials = ['email' => 'nonna@example.com', 'password' => 'password-lunga'];

        $this->postJson('https://rossi.photodaily.app/api/login', $credentials)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', 'Credenziali non valide.');

        $this->postJson('https://giopellino.photodaily.app/api/login', $credentials)->assertOk();
        $this->postJson('https://giopellino.it/api/login', $credentials)->assertOk();
        $this->postJson('https://photodaily.app/api/login', $credentials)->assertOk();
    }

    public function test_authenticated_routes_reject_tokens_of_another_family(): void
    {
        Sanctum::actingAs(User::factory()->for($this->giopellino)->create());

        $this->getJson('https://rossi.photodaily.app/api/me')->assertForbidden();
        $this->getJson('https://rossi.photodaily.app/api/photos')->assertForbidden();

        $this->getJson('https://giopellino.it/api/me')->assertOk();
        $this->getJson('https://photodaily.app/api/me')->assertOk();
    }

    public function test_invites_open_only_on_their_family_host(): void
    {
        $token = 'invito-di-prova';
        Invite::factory()->for($this->giopellino)->create(['token' => Invite::hashToken($token)]);

        $this->getJson("https://rossi.photodaily.app/api/invites/{$token}")->assertNotFound();
        $this->postJson("https://rossi.photodaily.app/api/invites/{$token}/accept", [
            'name' => 'Zia', 'password' => 'password-lunga', 'password_confirmation' => 'password-lunga',
        ])->assertNotFound();

        $this->getJson("https://giopellino.photodaily.app/api/invites/{$token}")->assertOk();
    }

    public function test_tls_ask_allows_only_known_hosts(): void
    {
        foreach (['photodaily.app', 'www.photodaily.app', 'giopellino.photodaily.app', 'giopellino.it', 'www.giopellino.it', 'rossi.photodaily.app'] as $domain) {
            $this->getJson('/api/tls/ask?domain='.$domain)->assertOk();
        }

        foreach (['', 'nessuno.photodaily.app', 'example.com', 'rossi.it'] as $domain) {
            $this->getJson('/api/tls/ask?domain='.$domain)->assertNotFound();
        }
    }

    public function test_forwarded_host_is_trusted_only_from_configured_proxies(): void
    {
        $headers = ['X-Forwarded-Host' => 'giopellino.photodaily.app'];

        $this->getJson('https://photodaily.app/api/site', $headers)
            ->assertJsonPath('data.family', null);

        config(['photodaily.trusted_proxies' => '127.0.0.1']);
        $this->app->getProvider(AppServiceProvider::class)->boot();

        $this->getJson('https://photodaily.app/api/site', $headers)
            ->assertJsonPath('data.family.slug', 'giopellino');
    }

    public function test_family_create_rejects_reserved_and_non_dns_slugs_and_bad_domains(): void
    {
        foreach (['www', 'api', 'Maiuscole', 'con_underscore', '-trattino', 'trattino-'] as $slug) {
            $this->assertSame(1, Artisan::call('family:create', [
                'slug' => $slug, 'name' => 'Prova', '--admin-email' => "a{$slug}@example.com", '--admin-name' => 'A', '--no-interaction' => true,
            ]), "Lo slug [{$slug}] doveva essere rifiutato.");
        }

        foreach (['non un dominio', 'bianchi.photodaily.app', 'giopellino.it'] as $domain) {
            $this->assertSame(1, Artisan::call('family:create', [
                'slug' => 'bianchi', 'name' => 'Bianchi', '--domain' => $domain, '--admin-email' => 'b@example.com', '--admin-name' => 'B', '--no-interaction' => true,
            ]), "Il dominio [{$domain}] doveva essere rifiutato.");
        }

        $this->assertSame(0, Artisan::call('family:create', [
            'slug' => 'bianchi', 'name' => 'Bianchi', '--domain' => 'WWW.Bianchi.it', '--admin-email' => 'b@example.com', '--admin-name' => 'B', '--no-interaction' => true,
        ]));
        $this->assertSame('bianchi.it', Family::where('slug', 'bianchi')->value('custom_domain'));
    }
}
