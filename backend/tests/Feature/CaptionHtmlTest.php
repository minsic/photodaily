<?php

namespace Tests\Feature;

use App\Models\Family;
use App\Models\Photo;
use App\Models\User;
use App\Rules\CaptionLength;
use App\Support\CaptionHtml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * La didascalia è l'unico campo in cui l'utente scrive HTML, e il diario
 * pubblico lo mostra a chiunque: qui si controlla che dal database esca
 * solo il formato ristretto dell'editor.
 */
class CaptionHtmlTest extends TestCase
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

    public function test_keeps_the_allowed_formatting(): void
    {
        $this->assertSame(
            '<p>Ciao <strong>mondo</strong> e <em>corsivo</em><br />a capo</p>',
            CaptionHtml::sanitize('<p>Ciao <strong>mondo</strong> e <em>corsivo</em><br>a capo</p>'),
        );
    }

    public function test_strips_scripts_handlers_and_dangerous_links(): void
    {
        $clean = CaptionHtml::sanitize(
            '<p onclick="rubaTutto()">Ciao<script>alert(1)</script>'
            .'<img src="x" onerror="alert(1)">'
            .'<a href="javascript:alert(1)">clic</a></p>'
        );

        $this->assertStringNotContainsString('script', (string) $clean);
        $this->assertStringNotContainsString('onclick', (string) $clean);
        $this->assertStringNotContainsString('onerror', (string) $clean);
        $this->assertStringNotContainsString('javascript:', (string) $clean);
        $this->assertSame('Ciaoclic', CaptionHtml::toPlainText($clean));
    }

    public function test_external_links_keep_href_and_get_safe_attributes(): void
    {
        $clean = (string) CaptionHtml::sanitize('<p><a href="https://esempio.it">qui</a></p>');

        $this->assertStringContainsString('href="https://esempio.it"', $clean);
        $this->assertStringContainsString('rel="nofollow noreferrer noopener"', $clean);
        $this->assertStringContainsString('target="_blank"', $clean);
    }

    public function test_plain_text_becomes_html_keeping_the_line_breaks(): void
    {
        $this->assertSame(
            '<p>prima<br>seconda</p><p>nuovo paragrafo</p>',
            CaptionHtml::sanitize("prima\nseconda\n\nnuovo paragrafo"),
        );
    }

    public function test_an_empty_editor_is_stored_as_null(): void
    {
        $this->assertNull(CaptionHtml::sanitize('<p></p>'));
        $this->assertNull(CaptionHtml::sanitize('   '));
        $this->assertNull(CaptionHtml::sanitize(null));
    }

    public function test_upload_stores_the_caption_sanitized(): void
    {
        $response = $this->postJson('/api/photos', [
            'image' => UploadedFile::fake()->image('foto.jpg', 40, 30),
            'data' => '2024-05-01',
            'didascalia' => '<p>Che <strong>giornata</strong><script>alert(1)</script></p>',
        ])->assertCreated();

        $this->assertSame('<p>Che <strong>giornata</strong></p>', $response->json('data.didascalia'));
    }

    public function test_update_sanitizes_and_can_clear_the_caption(): void
    {
        $photo = Photo::factory()->for($this->family)->create(['didascalia' => '<p>Vecchia</p>']);

        $this->patchJson("/api/photos/{$photo->id}", [
            'didascalia' => '<h1>Titolo</h1><p>Nuova</p>',
        ])->assertOk()->assertJsonPath('data.didascalia', 'Titolo<p>Nuova</p>');

        $this->patchJson("/api/photos/{$photo->id}", ['didascalia' => null])
            ->assertOk()
            ->assertJsonPath('data.didascalia', null);
    }

    public function test_the_limit_counts_written_characters_not_markup(): void
    {
        $photo = Photo::factory()->for($this->family)->create();

        // Testo al limite più il markup: come HTML supera i 5000 caratteri,
        // ma quello che l'utente ha scritto no.
        $long = str_repeat('a', CaptionLength::MAX - 200)
            .str_repeat('<strong>parola</strong> <em>corsivo</em> ', 10);

        $this->patchJson("/api/photos/{$photo->id}", ['didascalia' => "<p>{$long}</p>"])
            ->assertOk();

        $this->patchJson("/api/photos/{$photo->id}", [
            'didascalia' => '<p>'.str_repeat('a', CaptionLength::MAX + 1).'</p>',
        ])->assertUnprocessable()->assertJsonValidationErrors(['didascalia']);
    }
}
