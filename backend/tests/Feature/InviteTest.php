<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Family;
use App\Models\Invite;
use App\Models\User;
use App\Notifications\FamilyInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class InviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_a_new_member_by_email(): void
    {
        Notification::fake();

        $family = Family::factory()->create(['app_url' => 'https://giopellino.it']);
        $admin = User::factory()->admin()->for($family)->create();

        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/invites', ['email' => 'Nonna@Example.com'])
            ->assertCreated()
            ->assertJsonPath('data.email', 'nonna@example.com');

        $url = $response->json('data.url');
        $this->assertMatchesRegularExpression('#^https://giopellino\.it/invite/[A-Za-z0-9]{64}$#', $url);

        $invite = Invite::sole();
        $plainToken = basename($url);

        $this->assertSame($family->id, $invite->family_id);
        $this->assertSame($admin->id, $invite->invited_by);
        $this->assertSame(Invite::hashToken($plainToken), $invite->token, 'Nel DB si salva solo l\'hash del token.');
        $this->assertTrue($invite->expires_at->isFuture());

        Notification::assertSentTo(
            new AnonymousNotifiable,
            FamilyInvitation::class,
            fn (FamilyInvitation $notification, array $channels, AnonymousNotifiable $notifiable) => $notifiable->routes['mail'] === 'nonna@example.com'
                && $notification->url === $url,
        );
    }

    public function test_admin_sees_the_invites_of_their_own_family_with_status_and_expiry(): void
    {
        $family = Family::factory()->create();
        $admin = User::factory()->admin()->for($family)->create();

        $pending = Invite::factory()->for($family)->create(['email' => 'nonna@example.com', 'invited_by' => $admin->id]);
        $accepted = Invite::factory()->for($family)->accepted()->create(['email' => 'zio@example.com']);
        $expired = Invite::factory()->for($family)->expired()->create(['email' => 'zia@example.com']);
        $altrui = Invite::factory()->create(['email' => 'estranea@example.com']);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/invites')->assertOk();

        $this->assertEqualsCanonicalizing(
            [$pending->id, $accepted->id, $expired->id],
            $response->json('data.*.id'),
            'Gli inviti delle altre famiglie non compaiono.',
        );
        $this->assertNotContains($altrui->id, $response->json('data.*.id'));

        $byEmail = collect($response->json('data'))->keyBy('email');
        $this->assertSame('pendente', $byEmail['nonna@example.com']['stato']);
        $this->assertSame('accettato', $byEmail['zio@example.com']['stato']);
        $this->assertSame('scaduto', $byEmail['zia@example.com']['stato']);
        $this->assertNotEmpty($byEmail['nonna@example.com']['expires_at']);
        $this->assertSame($admin->name, $byEmail['nonna@example.com']['invitato_da']);

        // Il token non esce mai dall'API.
        $this->assertArrayNotHasKey('token', $byEmail['nonna@example.com']);
    }

    public function test_members_cannot_list_or_revoke_invites(): void
    {
        $family = Family::factory()->create();
        $invite = Invite::factory()->for($family)->create();

        Sanctum::actingAs(User::factory()->for($family)->create());

        $this->getJson('/api/invites')->assertForbidden();
        $this->deleteJson("/api/invites/{$invite->id}")->assertForbidden();

        $this->assertModelExists($invite);
    }

    public function test_admin_can_revoke_a_pending_invite(): void
    {
        $family = Family::factory()->create();
        $invite = Invite::factory()->for($family)->withToken('token-segreto')->create();

        Sanctum::actingAs(User::factory()->admin()->for($family)->create());

        $this->deleteJson("/api/invites/{$invite->id}")->assertNoContent();

        $this->assertModelMissing($invite);
        $this->getJson('/api/invites/token-segreto')->assertNotFound();
    }

    public function test_an_accepted_invite_cannot_be_revoked(): void
    {
        $family = Family::factory()->create();
        $invite = Invite::factory()->for($family)->accepted()->create();

        Sanctum::actingAs(User::factory()->admin()->for($family)->create());

        $this->deleteJson("/api/invites/{$invite->id}")
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Questo invito è già stato accettato.');

        $this->assertModelExists($invite);
    }

    public function test_an_admin_cannot_revoke_invites_of_another_family(): void
    {
        $invite = Invite::factory()->create();

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->deleteJson("/api/invites/{$invite->id}")->assertNotFound();

        $this->assertModelExists($invite);
    }

    public function test_members_cannot_invite(): void
    {
        Notification::fake();

        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/invites', ['email' => 'zio@example.com'])->assertForbidden();

        $this->assertDatabaseCount('invites', 0);
        Notification::assertNothingSent();
    }

    public function test_cannot_invite_an_email_that_already_has_an_account(): void
    {
        Notification::fake();

        $existing = User::factory()->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/invites', ['email' => $existing->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_invite_can_be_accepted_creating_a_member_of_the_inviting_family(): void
    {
        $family = Family::factory()->create();
        Invite::factory()->for($family)->withToken('token-segreto')->create(['email' => 'zia@example.com']);

        $this->getJson('/api/invites/token-segreto')
            ->assertOk()
            ->assertJsonPath('data.email', 'zia@example.com')
            ->assertJsonPath('data.family.name', $family->name);

        $response = $this->postJson('/api/invites/token-segreto/accept', [
            'name' => 'Zia Pina',
            'password' => 'una-password-lunga',
            'password_confirmation' => 'una-password-lunga',
        ])->assertCreated()
            ->assertJsonPath('user.email', 'zia@example.com')
            ->assertJsonPath('user.role', 'member')
            ->assertJsonPath('user.family.id', $family->id);

        $user = User::where('email', 'zia@example.com')->sole();
        $this->assertSame($family->id, $user->family_id);
        $this->assertSame(Role::Member, $user->role);
        $this->assertNotNull(Invite::sole()->accepted_at);

        $this->withToken($response->json('token'))
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'zia@example.com');
    }

    public function test_invite_cannot_be_accepted_twice(): void
    {
        Invite::factory()->withToken('token-segreto')->accepted()->create();

        $this->getJson('/api/invites/token-segreto')->assertNotFound();

        $this->postJson('/api/invites/token-segreto/accept', [
            'name' => 'Intruso',
            'password' => 'una-password-lunga',
            'password_confirmation' => 'una-password-lunga',
        ])->assertNotFound();

        $this->assertDatabaseMissing('users', ['name' => 'Intruso']);
    }

    public function test_expired_invite_cannot_be_accepted(): void
    {
        Invite::factory()->withToken('token-segreto')->expired()->create();

        $this->postJson('/api/invites/token-segreto/accept', [
            'name' => 'In ritardo',
            'password' => 'una-password-lunga',
            'password_confirmation' => 'una-password-lunga',
        ])->assertNotFound()
            ->assertJsonPath('message', 'Invito non valido, già utilizzato o scaduto.');
    }
}
