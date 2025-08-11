<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\Phtmal;
use Concept\Phtmal\PhtmalNodeInterface;

final class PhtmalRenderTest extends TestCase
{

    public function testPrettyAndMinifiedRendering(): void
    {
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li(['class' => 'x'], function (Phtmal $li) {
                $li->span('B');
            })
            ->li('C', ['data-k' => 'v'])->end();

        $pretty = $ul->render(false);
        $mini   = (string)$ul;

        $this->assertStringContainsString("\n", $pretty, 'Pretty output should contain newlines');
        $this->assertStringNotContainsString("\n", $mini, 'Minified output should be single-line');

        $this->assertStringContainsString('<ul>', $mini);
        $this->assertStringContainsString('</ul>', $mini);
        $this->assertStringContainsString('<li class="x"><span>B</span></li>', $mini);
        $this->assertStringContainsString('data-k="v"', $mini);
    }

    public function testTextEscapingAndRawHtml(): void
    {
        $div = new Phtmal('div', 'A <b>B</b>');
        $this->assertStringContainsString('A &lt;b&gt;B&lt;/b&gt;', (string)$div);

        $div2 = (new Phtmal('div'))->raw('<b>X</b>');
        $this->assertStringContainsString('<b>X</b>', $div2->render(true));
        $this->assertStringContainsString('<b>X</b>', $div2->render(false));
    }

    public function testBooleanAttributeShortForm(): void
    {
        $btn = new Phtmal('button', 'Go', ['disabled']);
        $s   = (string)$btn;
        $this->assertStringContainsString('<button disabled>', $s);
        $this->assertStringNotContainsString('disabled="disabled"', $s);
    }

    public function testAttrSetRemoveAndClassDedup(): void
    {
        $a = (new Phtmal('a', 'Open'))
            ->attr('href', '/docs')
            ->class('btn', 'btn', 'primary')
            ->attr('title', 'Click');
        $this->assertMatchesRegularExpression('/class="(btn primary|primary btn)"/', (string)$a);

        $a->attr('title', null);
        $this->assertStringNotContainsString('title="', (string)$a);
    }

}
