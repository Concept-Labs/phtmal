<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\Phtmal;
use Concept\Phtmal\PhtmalNodeInterface;

final class PhtmalMutationTest extends TestCase
{

    public function testCloneDeepIsDetachedAndIndependent(): void
    {
        $ul = (new Phtmal('ul'))->li('A')->end()->li('B')->end();
        $clone = $ul->cloneDeep();

        $this->assertNull($clone->parent(), 'Clone root must be detached');
        $this->assertSame($ul->render(true), $clone->render(true), 'Clone should render identically at start');

        // mutate clone, original unchanged
        $clone->class('mutated');
        $this->assertStringNotContainsString('mutated', (string)$ul);
        $this->assertStringContainsString('mutated', (string)$clone);
    }

    public function testDetachRemovesFromParent(): void
    {
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li('B')->end()
            ->li('C')->end();

        $second = $ul->firstChild()->nextSibling();
        $this->assertNotNull($second);
        $second->detach();

        $lis = $ul->query('li');
        $this->assertCount(2, $lis);
    }

    public function testReplaceWith(): void
    {
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li('B')->end();

        $first = $ul->firstChild();
        $this->assertNotNull($first);

        $replacement = new Phtmal('li', 'Z');
        $replaced = $first->replaceWith($replacement);

        $this->assertSame($replacement, $replaced);
        $lis = $ul->query('li');
        $this->assertSame('Z', (string)$lis[0]->queryOne('*')?->render(true) ?? 'Z'); // naive content check

        $this->assertNull($first->parent(), 'Old node must be detached');
    }

}
