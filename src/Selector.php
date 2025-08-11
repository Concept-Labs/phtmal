<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use InvalidArgumentException;

/**
 * {@inheritDoc}
 */
class Selector implements SelectorInterface
{
    /**
     * RegExp for a single "segment": optional combinator + simple selector.
     */
    protected const SEGMENT_PATTERN = <<<'REG'
        /
            (\s*[>+~]?\s*)                # combinator
            (
                (?:[a-z0-9]+|\*)?         # tag
                (?:\#[\w-]+)?             # id
                (?:\.[\w-]+)*             # classes
                (?:\[[^\]]+\])*           # attributes
                (?::[\w-]+(?:\([^)]+\))?)*# pseudos
            )
        /xi
        REG;

    /**
     * Attribute sub-pattern:
     *  [name], [name=value], [name^=v], [name$=v], [name*=v]
     * Value may be double-quoted, single-quoted, or unquoted.
     */
    protected const ATTRIBUTE_PATTERN =
        '/\[\s*([\w:\-]+)\s*(?:(\^=|\$=|\*=|=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*)?\]/';

    /**
     * Simple in-memory cache for tokenized selectors.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    protected static array $tokenCache = [];

    /** {@inheritDoc} */
    public static function select(PhtmalNodeInterface $root, string $selector): array
    {
        $hits = [];
        foreach (array_map('trim', explode(',', $selector)) as $sequence) {
            if ($sequence === '') {
                continue;
            }
            $parts = static::tokenize($sequence);
            static::walk($root, $parts, $hits);
        }

        // Deduplicate by object id, preserve order
        $seen = [];
        $out  = [];
        foreach ($hits as $node) {
            $id = spl_object_id($node);
            if (!isset($seen[$id])) { $seen[$id] = true; $out[] = $node; }
        }
        return $out;
    }

    /**
     * Tokenize selector into parts.
     *
     * @param string $selector
     * @return array<int,array<string,mixed>>
     */
    protected static function tokenize(string $selector): array
    {
        if ($selector === '') {
            throw new InvalidArgumentException('Empty selector');
        }

        if (isset(static::$tokenCache[$selector])) {
            return static::$tokenCache[$selector];
        }

        preg_match_all(static::SEGMENT_PATTERN, $selector, $matches, PREG_SET_ORDER);

        if (!$matches || implode('', array_column($matches, 0)) !== $selector) {
            throw new InvalidArgumentException("Unsupported selector: $selector");
        }

        // Remove empty segments, reindex, parse
        $segments = array_filter($matches, static fn($m) => trim($m[2]) !== '');
        $parts = array_values(array_map(
            static fn($seg) => static::parseSimple(
                trim($seg[2]),
                static::parseCombinator(trim($seg[1]))
            ),
            $segments
        ));

        return static::$tokenCache[$selector] = $parts;
    }

    /**
     * Map raw combinator string to enum value.
     *
     * @param string $raw
     * @return Combinator
     */
    protected static function parseCombinator(string $raw): Combinator
    {
        return match (trim($raw)) {
            '>' => Combinator::CHILD,
            '+' => Combinator::ADJACENT,
            '~' => Combinator::SIBLING,
            default => Combinator::DESCENDANT,
        };
    }

    /**
     * Parse a simple selector (single segment without combinator).
     *
     * @param string     $simple
     * @param Combinator $comb
     * @return array<string,mixed>
     */
    protected static function parseSimple(string $simple, Combinator $comb): array
    {
        $tag = $id = null;  $classes = [];  $attributes = [];  $pseudo = null;

        if (preg_match('/^[a-z0-9]+|\*/i', $simple, $m)) { $tag = strtolower($m[0]); }
        if (preg_match('/\#([\w\-]+)/',    $simple, $m)) { $id  = $m[1]; }
        if (preg_match_all('/\.([\w\-]+)/', $simple, $m)) { $classes = $m[1]; }

        // attributes: [name], [name=value], [name^=v], [name$=v], [name*=v]
        if (preg_match_all(static::ATTRIBUTE_PATTERN, $simple, $m, PREG_SET_ORDER)) {
            foreach ($m as $a) {
                $name = $a[1];
                $op   = $a[2] ?? null;
                $val  = $op === null ? null : ($a[3] ?? $a[4] ?? $a[5] ?? '');
                $attributes[] = [$name, $op, $val];
            }
        }

        if (preg_match('/:(first|last|nth)-child(?:\(([^)]+)\))?/', $simple, $m)) {
            $pseudo = [Pseudo::from($m[1]), $m[2] ?? null];
        }

        return compact('tag', 'id', 'classes', 'attributes', 'pseudo', 'comb');
    }

    /**
     * DFS with combinator logic.
     *
     * @param PhtmalNodeInterface $context
     * @param array<int,array<string,mixed>> $parts
     * @param array<int,PhtmalNodeInterface> $hits
     * @return void
     */
    protected static function walk(PhtmalNodeInterface $context, array $parts, array &$hits): void
    {
        if ($parts === []) { $hits[] = $context; return; }

        [$current, $rest] = [array_shift($parts), $parts];

        foreach (static::candidateNodes($context, $current['comb']) as $candidate) {
            if ($candidate && static::matches($candidate, $current)) {
                static::walk($candidate, $rest, $hits);
            }
        }
    }

    /**
     * Get candidate nodes for the next segment based on a combinator.
     *
     * @param PhtmalNodeInterface $context
     * @param Combinator          $c
     * @return PhtmalNodeInterface[]
     */
    protected static function candidateNodes(PhtmalNodeInterface $context, Combinator $c): array
    {
        // direct children
        if ($c === Combinator::CHILD) {
            return $context->_children();
        }

        // for +, ~ we need siblings relative to the current node in its parent
        $parent = $context->_parent();
        if (!$parent) {
            return ($c === Combinator::DESCENDANT) ? static::descendants($context) : [];
        }

        $siblings = $parent->_children();
        $i = array_search($context, $siblings, true);

        return match ($c) {
            Combinator::ADJACENT   => ($i === false || !isset($siblings[$i+1])) ? [] : [$siblings[$i+1]],
            Combinator::SIBLING    => ($i === false) ? [] : array_slice($siblings, $i+1),
            Combinator::DESCENDANT => static::descendants($context),
            default                => [],
        };
    }

    /**
     * Pre-order DFS collecting all descendants (excluding the node itself).
     *
     * @param PhtmalNodeInterface $node
     * @return PhtmalNodeInterface[]
     */
    protected static function descendants(PhtmalNodeInterface $node): array
    {
        $stack = [$node];   $all = [];
        while ($stack) {
            foreach (array_pop($stack)->_children() as $child) {
                $all[] = $child;   // visit
                $stack[] = $child; // push children
            }
        }
        return $all;
    }

    /**
     * Check whether a single node matches a parsed selector part.
     *
     * @param PhtmalNodeInterface  $node
     * @param array<string,mixed>  $sel
     * @return bool
     */
    protected static function matches(PhtmalNodeInterface $node, array $sel): bool
    {
        // Tag
        if ($sel['tag'] && $sel['tag'] !== '*' && $node->getTag() !== $sel['tag']) {
            return false;
        }
        // ID
        if ($sel['id'] && !in_array($sel['id'], $node->_attr()['id'] ?? [], true)) {
            return false;
        }
        // Classes
        foreach ($sel['classes'] as $class) {
            if (!in_array($class, $node->_attr()['class'] ?? [], true)) {
                return false;
            }
        }
        // Attributes
        foreach ($sel['attributes'] as [$key, $op, $expected]) {
            $has = array_key_exists($key, $node->_attr());
            if (!$has) {
                return false; // presence required
            }
            if ($op === null) {
                continue;     // presence-only [attr]
            }

            $values  = $node->_attr()[$key];   // list of strings
            $matched = false;

            foreach ($values as $actual) {
                switch ($op) {
                    case '=':  $matched = ($actual === $expected); break;
                    case '^=': $matched = ($expected === '' || str_starts_with($actual, $expected)); break;
                    case '$=': $matched = ($expected === '' || str_ends_with($actual, $expected));   break;
                    case '*=': $matched = ($expected === '' || strpos($actual, $expected) !== false); break;
                    default:   $matched = false;
                }
                if ($matched) { break; }
            }
            if (!$matched) {
                return false;
            }
        }
        // Pseudo-classes
        if ($sel['pseudo'] && !static::matchPseudo($node, ...$sel['pseudo'])) {
            return false;
        }
        return true;
    }

    /**
     * Evaluate supported pseudo-classes for a node.
     *
     * @param PhtmalNodeInterface $node
     * @param Pseudo              $pseudo
     * @param string|null         $arg
     * @return bool
     */
    protected static function matchPseudo(PhtmalNodeInterface $node, Pseudo $pseudo, ?string $arg): bool
    {
        $siblings = $node->_parent()?->_children() ?? [];
        $index    = array_search($node, $siblings, true);
        $count    = count($siblings);

        return match ($pseudo) {
            Pseudo::FIRST_CHILD => $index === 0,
            Pseudo::LAST_CHILD  => $index === $count - 1,
            Pseudo::NTH_CHILD   => static::nthCheck($index, $arg),
        };
    }

    /**
     * Check :nth-child argument (integer n, "odd", "even").
     *
     * @param int|null    $index
     * @param string|null $arg
     * @return bool
     */
    protected static function nthCheck(?int $index, ?string $arg): bool
    {
        if ($index === null) { return false; }
        $n = $index + 1;
        return match ($arg) {
            'odd'  => $n % 2 === 1,
            'even' => $n % 2 === 0,
            default => ctype_digit((string)$arg) && $n === (int)$arg,
        };
    }
}

/**
 * Enum Combinator
 *
 * CSS combinator kinds supported by the selector engine.
 */
enum Combinator
{
    /** Descendant combinator: A B */
    case DESCENDANT;

    /** Direct child combinator: A > B */
    case CHILD;

    /** Adjacent sibling combinator: A + B */
    case ADJACENT;

    /** General sibling combinator: A ~ B */
    case SIBLING;
}

/**
 * Enum Pseudo
 *
 * Supported pseudo-classes for matching sibling positions.
 */
enum Pseudo: string
{
    /** :first-child */
    case FIRST_CHILD = 'first';

    /** :last-child */
    case LAST_CHILD  = 'last';

    /** :nth-child() – supports integer n, "odd", "even". */
    case NTH_CHILD   = 'nth';
}