<?php

namespace Tests\Unit;

use App\Support\PortableText;
use PHPUnit\Framework\TestCase;

class PortableTextTest extends TestCase
{
    public function test_concatenates_the_children_text_of_each_block(): void
    {
        $blocks = [
            ['_type' => 'block', 'children' => [
                ['_type' => 'span', 'text' => 'Ciao ', 'marks' => ['strong']],
                ['_type' => 'span', 'text' => 'mondo'],
            ]],
            ['_type' => 'block', 'children' => [['_type' => 'span', 'text' => 'Seconda riga']]],
        ];

        $this->assertSame("Ciao mondo\nSeconda riga", PortableText::toPlainText($blocks));
    }

    public function test_skips_non_text_blocks_and_empty_blocks(): void
    {
        $blocks = [
            ['_type' => 'image', 'asset' => ['_ref' => 'image-1']],
            ['_type' => 'block', 'children' => [['_type' => 'span', 'text' => '  ']]],
            ['_type' => 'block', 'children' => [['_type' => 'span', 'text' => 'Testo']]],
        ];

        $this->assertSame('Testo', PortableText::toPlainText($blocks));
    }

    public function test_handles_missing_or_plain_values(): void
    {
        $this->assertNull(PortableText::toPlainText(null));
        $this->assertNull(PortableText::toPlainText([]));
        $this->assertSame('Già testo', PortableText::toPlainText(' Già testo '));
        $this->assertSame('Blocco singolo', PortableText::toPlainText(
            ['_type' => 'block', 'children' => [['_type' => 'span', 'text' => 'Blocco singolo']]],
        ));
    }
}
