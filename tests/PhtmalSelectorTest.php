<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Concept\Phtmal\Phtmal;
use Concept\Phtmal\PhtmalNodeInterface;

final class PhtmalSelectorTest extends TestCase
{

    public function testSimpleTagClassIdSelectors(): void
    {
        $root = (new Phtmal('div', null, ['id' => 'root']))
            ->append((new Phtmal('p', 'one', ['class' => 'a'])))
            ->append((new Phtmal('p', 'two', ['class' => 'a b'])))
            ->append((new Phtmal('span', 'x', ['id' => 'xid'])));

        $byTag   = $root->query('p');
        $byClass = $root->query('.b');
        $byId    = $root->query('#xid');

        $this->assertCount(2, $byTag);
        $this->assertCount(1, $byClass);
        $this->assertCount(1, $byId);
        $this->assertSame('span', $byId[0]->getTag());
    }

    public function testAttributeOperators(): void
    {
        $root = new Phtmal('div');
        $root
            ->append((new Phtmal('i', null, ['data-x' => 'foo'])))
            ->append((new Phtmal('i', null, ['data-x' => 'barfoo'])))
            ->append((new Phtmal('i', null, ['data-x' => 'foobar'])));

        $eq  = $root->query('i[data-x=foo]');
        $pre = $root->query('i[data-x^=foo]');
        $suf = $root->query('i[data-x$=foo]');
        $con = $root->query('i[data-x*=of]');

        $this->assertCount(1, $eq);
        $this->assertCount(2, $pre);
        $this->assertCount(2, $suf);
        $this->assertCount(2, $con);
    }

    public function testCombinatorsAndPseudos(): void
    {
        $div = new Phtmal('div');
        $p1 = new Phtmal('p', 'a', ['class' => 'x']);
        $p2 = new Phtmal('p', 'b', ['class' => 'y']);
        $span = new Phtmal('span', 's');
        $div->append($p1)->append($p2)->append($span);

        $desc = $div->query('div p');
        $child = $div->query('div > p');
        $adj   = $div->query('p + span');
        $sib   = $div->query('p ~ span');

        $this->assertCount(2, $desc);
        $this->assertCount(2, $child);
        $this->assertCount(1, $adj);
        $this->assertCount(1, $sib);
        $this->assertSame('span', $adj[0]->getTag());

        // pseudos
        $ul = (new Phtmal('ul'))
            ->li('A')->end()
            ->li('B')->end()
            ->li('C')->end();

        $first = $ul->query('li:first-child');
        $last  = $ul->query('li:last-child');
        $nth2  = $ul->query('li:nth-child(2)');

        $this->assertCount(1, $first);
        $this->assertCount(1, $last);
        $this->assertCount(1, $nth2);
        $this->assertSame('li', $nth2[0]->getTag());
    }

}
