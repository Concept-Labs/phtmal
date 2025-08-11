<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use InvalidArgumentException;

/**
 * Interface SelectorInterface
 *
 * Minimal CSS-like selector engine over PhtmalNodeInterface trees.
 */
interface SelectorInterface
{
    /**
     * Return all descendants of $root that match $selector.
     *
     * @param PhtmalNodeInterface $root
     * @param string              $selector Comma-separated selector list.
     *
     * @return PhtmalNodeInterface[] Matched nodes (deduped, stable order).
     *
     * @throws InvalidArgumentException If selector is empty/unsupported.
     */
    public static function select(PhtmalNodeInterface $root, string $selector): array;
}
