<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\DomHtmlParser;
use Concept\Phtmal\Phtmal;
use Concept\Phtmal\PhtmalNodeInterface;

final class HtmlParserTest extends TestCase
{
    public function testParseFragmentSimpleList(): void
    {
        $parser = new DomHtmlParser();
        $root = $parser->parseFragment('<li>A</li><li class="x">B</li>', 'ul');

        $this->assertSame('ul', $root->getTag());
        $lis = $root->query('li');
        $this->assertCount(2, $lis);
        $this->assertStringContainsString('<ul><li>A</li><li class="x">B</li></ul>', (string)$root);
    }

    public function testParseScriptAsRaw(): void
    {
        $html = '<script>if (a < b) { alert("x"); }</script>';
        $parser = new DomHtmlParser();
        $root = $parser->parseFragment($html, 'div');

        $this->assertStringContainsString('<script>if (a < b) { alert("x"); }</script>', (string)$root);
        // pretty render should also keep raw content
        $this->assertStringContainsString('if (a < b) { alert("x"); }', $root->render(false));
    }

    public function testParseDocumentReturnsHtmlRoot(): void
    {
        $doc = '<!doctype html><html><body><div id="x">t</div></body></html>';
        $parser = new DomHtmlParser();
        $root = $parser->parseDocument($doc);

        $this->assertSame('html', $root->getTag());
        $div = $root->queryOne('#x');
        $this->assertNotNull($div);
        $this->assertSame('div', $div->getTag());
    }

    public function testBooleanAttributeParsing(): void
    {
        $parser = new DomHtmlParser();
        $root = $parser->parseFragment('<input disabled>', 'div');

        $input = $root->queryOne('input');
        $this->assertNotNull($input);
        $this->assertStringContainsString('<input disabled>', (string)$root);
    }

    public function testWhitespaceSkippingOutsidePre(): void
    {
        $parser = new DomHtmlParser();
        $root = $parser->parseFragment("<div>   \n  <span>x</span>  </div>", 'div', ['preserveWhitespace' => false]);

        $this->assertStringNotContainsString('  ', (string)$root);
        $this->assertStringContainsString('<span>x</span>', (string)$root);
    }

    public function testPreWhitespacePreserved(): void
    {
        $parser = new DomHtmlParser();
        $root = $parser->parseFragment("<pre>  a\n  b</pre>", 'div', ['preserveWhitespace' => false, 'preservePreWhitespace' => true]);

        $pretty = $root->render(false);
        $this->assertStringContainsString("  a\n", $pretty);
    }
}
