<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use InvalidArgumentException;
use RuntimeException;

/**
 * Phtmal – tiny, fluent HTML node tree.
 *
 * Example:
 *   $html = (new Phtmal('ul'))
 *              ->li('A')->end()
 *              ->li('B')->end()
 *          ->top();
 *   echo $html->render();          // pretty
 *   echo (string)$html;            // minified
 */
final class Phtmal
{
    /* -----------------------------------------------------------------
     * Configuration constants
     * ----------------------------------------------------------------- */
    /** Void (self-closing) HTML elements. */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** Pretty-print indent string and newline. */
    private const INDENT = '  ';
    private const NL     = "\n";

    /* -----------------------------------------------------------------
     * Internal state
     * ----------------------------------------------------------------- */
    private ?self  $parent   = null;
    private array  $children = [];

    /** Tag name, converted to lower-case. */
    public  readonly string  $tag;

    /** Normalised attributes (array of string lists). */
    private array  $attributes = [];

    /** Optional text content (raw, escaped on render). */
    private ?string $text;

    /* -----------------------------------------------------------------
     * Construction
     * ----------------------------------------------------------------- */
    /**
     * @param string      $tag       HTML tag (default “div”)
     * @param string|null $text      Optional text content
     * @param array       $attr      Attribute map (`['class'=>['x','y']]`)
     * @throws InvalidArgumentException
     */
    public function __construct(
        string $tag = 'div',
        ?string $text = null,
        array $attr = []
    ) {
        $tag = strtolower(trim($tag));
        if ($tag === '') {
            throw new InvalidArgumentException('Tag name cannot be empty.');
        }

        $this->tag        = $tag;
        $this->text       = $text;
        $this->attributes = self::normaliseAttributes($attr);
    }

    /* -----------------------------------------------------------------
     * Fluent builder
     * ----------------------------------------------------------------- */
    /**
     * Magic child-builder: `$ul->li('item', ['class'=>'x'])`.
     *
     * @param string $tag   Child element tag.
     * @param array  $args  [0] => text | attributes, [1] => attributes
     * @return self         The newly created child.
     */
    public function __call(string $tag, array $args): self
    {
        $callback = null;
        if ($args && is_callable(end($args))) {         // last arg is callable
            $callback = array_pop($args);
        }

        [$text, $attr] = $args + [null, []];

        $child = new self(
            $tag,
            is_string($text) ? $text : null,
            is_array($text)  ? $text : $attr
        );
        $child->parent   = $this;
        $this->children[] = $child;

        if ($callback) {
            $callback($child);     // build subtree
            return $this;          // jump back automatically
        }
        return $child;             // old behaviour (manual end)
    }


    /**
     * Finishes the current element and returns to its parent.
     *
     * @throws \RuntimeException If already at the root element.
     * @return self The parent element.
     */
    public function end(): self
    {
        return $this->parent
            ?? throw new RuntimeException('Already at root.');
    }

    /**
     * Jumps to the root element of the current tree.
     *
     * @return self The root element.
     */
    public function top(): self
    {
        $node = $this;
        while ($node->parent) { $node = $node->parent; }

        return $node;
    }

    
    /**
     * Generic attribute setter / remover.
     * 
     * @param string $name  Attribute name
     * @param string|array|null $value  Single value or array of values, or
     *                                  `null` to remove the attribute.
     * @return static
     * @throws InvalidArgumentException If the attribute name is invalid.
     */
    public function attr(string $name, string|array|null $value = null): static
    {
        if ($name === '' || !preg_match('/^[a-zA-Z_][\w-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid attribute name: '$name'");
        }
        if ($value === null) {
            unset($this->attributes[$name]);
            return $this;
        }

        $this->attributes[$name] = is_array($value)
            ? array_values($value)
            : [$value];

        return $this;
    }

    /**
     * Sugar for `id="…"`
     * Set the `id` attribute.
     * @param string $id The ID value.
     * 
     * @return static
     */
    public function id(string $id): static
    {
        return $this->attr('id', $id);
    }

    /**
     * Sugar for class manipulation.
     * Deduplicates automatically.
     * 
     * @param string ...$class Class names
     * @return static
     */
    public function class(string ...$class): static
    {
        $current = $this->attributes['class'] ?? [];
        return $this->attr('class', array_unique([...$current, ...$class]));
    }

    /* -----------------------------------------------------------------
     * Rendering
     * ----------------------------------------------------------------- */
    /** Cast to string – minified HTML. */
    public function __toString(): string
    {
        return $this->render(minify: true);
    }

    /**
     * Render element to HTML.
     *
     * @param bool $minify      `true` → single-line, `false` → pretty.
     * @param int  $indentLevel Current indent level for recursion.
     */
    /* -----------------------------------------------------------------
 * Phtmal::render() – pretty-print overhaul
 * ----------------------------------------------------------------- */

    public function render(bool $minify = false, int $indentLevel = 0): string
    {
        // --- trivial minified path (unchanged) -------------------------
        if ($minify) {
            $attr  = self::renderAttributes($this->attributes);
            $text  = htmlspecialchars($this->text ?? '', ENT_QUOTES | ENT_SUBSTITUTE);
            $html  = "<{$this->tag}{$attr}>";
            if (!in_array($this->tag, self::VOID_ELEMENTS, true)) {
                foreach ($this->children as $child) {
                    $text .= $child->render(true);
                }
                $html .= "{$text}</{$this->tag}>";
            }
            return $html;
        }

        // --- pretty-print branch --------------------------------------
        $indent      = str_repeat(self::INDENT, $indentLevel);
        $innerIndent = $indent . self::INDENT;
        $newline     = self::NL;

        // 1) attributes
        $attr = self::renderAttributes($this->attributes);

        // 2) void element
        if (in_array($this->tag, self::VOID_ELEMENTS, true)) {
            return "{$indent}<{$this->tag}{$attr} />{$newline}";
        }

        // 3) collect inner lines: optional text + each child line
        $innerLines = [];

        if ($this->text !== null && $this->text !== '') {
            $innerLines[] = $innerIndent .
                            htmlspecialchars($this->text, ENT_QUOTES | ENT_SUBSTITUTE);
        }

        foreach ($this->children as $child) {
            $innerLines[] = rtrim($child->render(false, $indentLevel + 1), $newline);
        }

        // 4) build output
        if ($innerLines === []) {           // empty element
            return "{$indent}<{$this->tag}{$attr}></{$this->tag}>{$newline}";
        }

        $inner = $newline . implode($newline, $innerLines) . $newline . $indent;

        return "{$indent}<{$this->tag}{$attr}>{$inner}</{$this->tag}>{$newline}";
    }

    /**
     * Convert the attribute map to a string:
     * ['class'=>['a','b'], 'id'=>['foo']]
     *   →  ' class="a b" id="foo"'
     *
     * Escapes both names and values.
     */
    private static function renderAttributes(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $values) {
            $out .= ' ' .
                    htmlspecialchars($name) .
                    '="' .
                    htmlspecialchars(implode(' ', $values)) .
                    '"';
        }
        return $out;
    }


    /* -----------------------------------------------------------------
     * CSS-like querying
     * ----------------------------------------------------------------- */
    /**
     * Find descendants matching a (comma-separated) CSS selector subset.
     *
     * Supports:
     *   tag, *, #id, .class, [attr=value]
     *   combinators: ' ', '>', '+', '~'
     *   pseudo-classes: :first-child, :last-child, :nth-child(n|odd|even)
     *
     * @return self[] matched nodes (deduplicated, original order).
     */
    public function query(string $selector): array
    {
        return Selector::select($this, $selector);
    }

    /* -----------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------- */
    /** Normalise attribute input to list-of-strings. */
    private static function normaliseAttributes(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_int($key)) {            // allow ['disabled']
                $out[$value] = [$value];
                continue;
            }
            $out[$key] = is_array($value)
                ? array_values(array_map('strval', $value))
                : [(string)$value];
        }
        return $out;
    }

    /* -- Internal getters for Selector (package-internal) -------------- */
    /** @internal */ public function _children(): array { return $this->children; }
    /** @internal */ public function _parent(): ?self   { return $this->parent;   }
    /** @internal */ public function _attr(): array     { return $this->attributes; }
}

/* ------------------------------------------------------------------ */
/* Selector – minimal CSS subset engine                               */
/* ------------------------------------------------------------------ */
final class Selector
{
    /** Segment regexp: combinator + simple selector. */
    private const SEGMENT_PATTERN = <<<'REG'
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

    /** Attribute sub-pattern for readability. */
    private const ATTRIBUTE_PATTERN = '/\[([\w-]+)(?:=([^\]]+))?\]/';

    /* ---------- Public API -------------------------------------------- */

    /**
     * Return all descendants of $root that match $selector.
     *
     * @param Phtmal $root
     * @param string $selector  Comma-separated selector list.
     * @return Phtmal[]
     */
    public static function select(Phtmal $root, string $selector): array
    {
        $hits = [];
        foreach (array_map('trim', explode(',', $selector)) as $sequence) {
            $parts = self::tokenize($sequence);
            self::walk($root, $parts, $hits);
        }
        /** @noinspection PhpStrictTypeCheckingInspection */
        return array_values(array_unique($hits, SORT_REGULAR));
    }

    /* ---------- Lexer + parser ---------------------------------------- */

    /**
     * Split selector string into parsed “parts”.
     *
     * Each part is an associative array with:
     *   tag, id, classes, attrs, pseudo, comb
     */
    private static function tokenize(string $selector): array
    {
        if ($selector === '') {
            throw new InvalidArgumentException('Empty selector');
        }

        preg_match_all(self::SEGMENT_PATTERN, $selector, $matches, PREG_SET_ORDER);

        if (!$matches || implode('', array_column($matches, 0)) !== $selector) {
            throw new InvalidArgumentException("Unsupported selector: $selector");
        }

        // Remove empty segments, reindex, parse
        $segments = array_filter($matches, static fn($m) => trim($m[2]) !== '');
        return array_values(array_map(
            static fn($seg) => self::parseSimple(
                trim($seg[2]),
                self::parseCombinator(trim($seg[1]))
            ),
            $segments
        ));
    }

    /** Map raw combinator string to enum value. */
    private static function parseCombinator(string $raw): Combinator
    {
        return match (trim($raw)) {
            '>' => Combinator::CHILD,
            '+' => Combinator::ADJACENT,
            '~' => Combinator::SIBLING,
            default => Combinator::DESCENDANT,
        };
    }

    /** Parse a “simple selector” (no combinator). */
    private static function parseSimple(string $simple, Combinator $comb): array
    {
        $tag = $id = null;  $classes = [];  $attributes = [];  $pseudo = null;

        if (preg_match('/^[a-z0-9]+|\*/i', $simple, $m)) { $tag = strtolower($m[0]); }
        if (preg_match('/\#([\w\-]+)/',    $simple, $m)) { $id  = $m[1]; }
        if (preg_match_all('/\.([\w\-]+)/', $simple, $m)) { $classes = $m[1]; }

        if (preg_match_all(self::ATTRIBUTE_PATTERN, $simple, $m, PREG_SET_ORDER)) {
            foreach ($m as $attrMatch) {
                $attributes[] = [$attrMatch[1], $attrMatch[2] ?? null];
            }
        }

        if (preg_match('/:(first|last|nth)-child(?:\(([^)]+)\))?/', $simple, $m)) {
            $pseudo = [Pseudo::from($m[1]), $m[2] ?? null];
        }

        return compact('tag', 'id', 'classes', 'attributes', 'pseudo', 'comb');
    }

    /* ---------- Tree traversal ---------------------------------------- */

    /** Depth-first search with combinator logic. */
    private static function walk(Phtmal $context, array $parts, array &$hits): void
    {
        if ($parts === []) { $hits[] = $context; return; }

        [$current, $rest] = [array_shift($parts), $parts];

        foreach (self::candidateNodes($context, $current['comb']) as $candidate) {
            if ($candidate && self::matches($candidate, $current)) {
                self::walk($candidate, $rest, $hits);
            }
        }
    }

    /**
     * Get candidate nodes for the next selector step, based on combinator.
     *
     * @return Phtmal[]
     */
    private static function candidateNodes(Phtmal $context, Combinator $c): array
    {
        return match ($c) {
            Combinator::CHILD      => $context->_children(),
            Combinator::ADJACENT   => [$context->_children()[0] ?? null],
            Combinator::SIBLING    => array_slice($context->_parent()?->_children() ?? [], 1),
            Combinator::DESCENDANT => self::descendants($context),
        };
    }

    /** Pre-order DFS collecting all descendants (excluding $node itself). */
    private static function descendants(Phtmal $node): array
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

    /* ---------- Matching ------------------------------------------------ */

    /** Check if a single node matches a parsed selector “part”. */
    private static function matches(Phtmal $node, array $sel): bool
    {
        // Tag
        if ($sel['tag'] && $sel['tag'] !== '*' && $node->tag !== $sel['tag']) {
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
        foreach ($sel['attributes'] as [$key, $value]) {
            if (!isset($node->_attr()[$key])) { return false; }
            if ($value !== null && !in_array($value, $node->_attr()[$key], true)) {
                return false;
            }
        }
        // Pseudo-classes
        if ($sel['pseudo'] && !self::matchPseudo($node, ...$sel['pseudo'])) {
            return false;
        }
        return true;
    }

    /** Evaluate supported pseudo-classes. */
    private static function matchPseudo(Phtmal $node, Pseudo $pseudo, ?string $arg): bool
    {
        $siblings = $node->_parent()?->_children() ?? [];
        $index    = array_search($node, $siblings, true);
        $count    = count($siblings);

        return match ($pseudo) {
            Pseudo::FIRST_CHILD => $index === 0,
            Pseudo::LAST_CHILD  => $index === $count - 1,
            Pseudo::NTH_CHILD   => self::nthCheck($index, $arg),
        };
    }

    /** Evaluate nth-child arguments. */
    private static function nthCheck(?int $index, ?string $arg): bool
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

/* ---------- Enumerations for stronger typing ------------------------ */
enum Combinator { case DESCENDANT; case CHILD; case ADJACENT; case SIBLING; }
enum Pseudo: string
{
    case FIRST_CHILD = 'first';
    case LAST_CHILD  = 'last';
    case NTH_CHILD   = 'nth';
}
