<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\Phtmal;
use InvalidArgumentException;

final class PhtmalEdgeCasesTest extends TestCase
{
    public function testEmptySelectorThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Phtmal('div'))->query('');
    }

    public function testInvalidAttributeNameThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Phtmal('div'))->attr('1bad', 'x');
    }

    public function testVoidElementRenderingIgnoresChildren(): void
    {
        $img = (new Phtmal('img', null, ['alt' => 'x']))
            ->raw('<b>RAW</b>') // even raw children should not appear for void
            ->__call('span', ['ignored']); // simulate child

        $pretty = $img->render(false);
        $mini   = (string)$img;

        $this->assertStringContainsString('<img alt="x">', $mini);
        $this->assertStringNotContainsString('</img>', $mini);
        $this->assertStringNotContainsString('RAW', $mini);
        $this->assertMatchesRegularExpression('#^<img alt="x">\R?$#', trim($pretty));
    }

    public function testAdjacentAndSiblingSelectors(): void
    {
        $ul = new Phtmal('ul');
        $li1 = new Phtmal('li', '1');
        $li2 = new Phtmal('li', '2');
        $li3 = new Phtmal('li', '3');

        $ul->append($li1)->append($li2)->append($li3);

        $adj = $ul->query('li + li');
        $sib = $ul->query('li ~ li');

        // li + li should match li2 (adjacent to li1) and li3 (adjacent to li2)
        $this->assertCount(2, $adj);

        // li ~ li should match all following siblings for each li => li2, li3 (dedup preserves order)
        $this->assertCount(2, $sib);
    }

    public function testNthChildOddEven(): void
    {
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li('B')->end()
            ->li('C')->end();

        $odd  = $ul->query('li:nth-child(odd)');
        $even = $ul->query('li:nth-child(even)');

        $this->assertCount(2, $odd);  // 1st, 3rd
        $this->assertCount(1, $even); // 2nd
    }

    public function testAttributePresenceSelector(): void
    {
        $root = new Phtmal('div');
        $root->append(new Phtmal('input', null, ['disabled']));
        $hits = $root->query('input[disabled]');
        $this->assertCount(1, $hits);
    }
}
