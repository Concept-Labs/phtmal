<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\Phtmal;
use Concept\Phtmal\PhtmalNodeInterface;

final class PhtmalBuilderTest extends TestCase
{

    public function testCallWithCallbackReturnsParent(): void
    {
        $ul = new Phtmal('ul');
        $ret = $ul->li(['class' => 'x'], function (Phtmal $li) {
            $li->span('Z');
        });
        $this->assertSame($ul, $ret, '__call with callback should return parent');
    }

    public function testCallWithoutCallbackReturnsChild(): void
    {
        $ul = new Phtmal('ul');
        $child = $ul->li('A');
        $this->assertInstanceOf(Phtmal::class, $child);
        $this->assertSame('li', $child->getTag());
        $this->assertSame($ul, $child->end());
    }

    public function testEndAtRootThrows(): void
    {
        $this->expectException(RuntimeException::class);
        (new Phtmal('div'))->end();
    }

    public function testNavigationHelpers(): void
    {
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li('B')->end()
            ->li('C')->end();

        $first = $ul->firstChild();
        $this->assertNotNull($first);
        $this->assertSame('li', $first->getTag());

        $next = $first->nextSibling();
        $this->assertNotNull($next);
        $this->assertSame('li', $next->getTag());
        $this->assertSame($ul, $first->parent());
    }

}
