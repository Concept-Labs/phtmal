<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use InvalidArgumentException;
use RuntimeException;

/**
 * Class Phtmal
 *
 * Tiny, fluent HTML node tree with a minimal CSS-like query engine.
 * Supports pretty/minified rendering, attribute normalization, text and raw
 * HTML nodes, and a convenient fluent builder via __call().
 *
 * Usage example:
 *  $html = (new Phtmal('ul'))
 *      ->li(['class' => 'item'], function ($li) {
 *          $li->span('A');
 *      })
 *      ->li('B')->end()
 *  ->top();
 *
 *  echo $html->render();   // pretty
 *  echo (string)$html;     // minified
 *
 * @package Concept\Phtmal
 */
final class Phtmal
{
    /* -----------------------------------------------------------------
     * Configuration constants
     * ----------------------------------------------------------------- */

    /**
     * List of void (self-closing) HTML elements.
     * Note: in HTML5 they can be rendered without a trailing slash.
     *
     * @var string[]
     */
    private const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /**
     * Indentation string for pretty rendering.
     */
    private const INDENT = '  ';

    /**
     * Newline string for pretty rendering.
     */
    private const NL = "\n";

    /* -----------------------------------------------------------------
     * Internal state
     * ----------------------------------------------------------------- */

    /**
     * Parent node or null if this is the root.
     *
     * @var self|null
     */
    private ?self $parent = null;

    /**
     * List of child nodes.
     *
     * @var self[]
     */
    private array $children = [];

    /**
     * Tag name, normalized to lower-case.
     * Special internal tags:
     *  - "#text" – escaped text node
     *  - "#raw"  – unescaped (raw) HTML node
     *
     * @var string
     */
    public readonly string $tag;

    /**
     * Normalized attributes. Each attribute is a list of string values.
     * E.g. ['class' => ['a','b'], 'disabled' => ['disabled']]
     *
     * @var array<string,string[]>
     */
    private array $attributes = [];

    /**
     * Optional text content for element or text/raw node payload.
     * (Escaped for normal elements; raw for "#raw" node.)
     *
     * @var string|null
     */
    private ?string $text;

    /* -----------------------------------------------------------------
     * Construction
     * ----------------------------------------------------------------- */

    /**
     * Phtmal constructor.
     *
     * @param string      $tag   HTML tag or internal (“#text”, “#raw”), default: "div"
     * @param string|null $text  Optional text/HTML payload
     * @param array       $attr  Attributes (e.g. ['class'=>['x','y']] or ['disabled'])
     *
     * @throws InvalidArgumentException If the tag is empty.
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
     * Magic child-builder.
     *
     * Examples:
     *  $ul->li('item', ['class'=>'x']);
     *  $ul->li(['class'=>'x'], fn(Phtmal $li)=>$li->span('X'));
     *
     * Behavior:
     *  - if the last argument is a callable, it is treated as a subtree builder;
     *    after invocation, this method returns the current node (parent)
     *  - otherwise returns the newly created child node (manual ->end() if needed)
     *
     * @param string $tag  Child element tag.
     * @param array  $args [0] => text|attributes, [1] => attributes, [last] => ?callable
     *
     * @return self Child or parent depending on whether a callback was provided.
     */
    public function __call(string $tag, array $args): self
    {
        $callback = null;
        if ($args && is_callable(end($args))) {
            $callback = array_pop($args);
        }

        [$text, $attr] = $args + [null, []];

        $child = new self(
            $tag,
            is_string($text) ? $text : null,
            is_array($text)  ? $text : $attr
        );
        $child->parent     = $this;
        $this->children[]  = $child;

        if ($callback) {
            $callback($child);
            return $this; // jump back automatically
        }
        return $child;     // manual chaining (call ->end() yourself)
    }

    /**
     * Finish the current element and return to its parent.
     *
     * @return self
     *
     * @throws RuntimeException If already at the root element.
     */
    public function end(): self
    {
        return $this->parent
            ?? throw new RuntimeException('Already at root.');
    }

    /**
     * Jump to the root element of the current tree.
     *
     * @return self Root node.
     */
    public function top(): self
    {
        $node = $this;
        while ($node->parent) { $node = $node->parent; }
        return $node;
    }

    /* -----------------------------------------------------------------
     * Convenience API
     * ----------------------------------------------------------------- */

    /**
     * Set or clear text content of the current element.
     *
     * @param string|null $text New text content, or null to clear.
     *
     * @return static
     */
    public function text(?string $text): static
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Append a child node or a text node.
     * If string is provided, a "#text" node is appended (escaped on render).
     *
     * @param self|string $nodeOrText Node to append or plain text content.
     *
     * @return static
     */
    public function append(self|string $nodeOrText): static
    {
        if (is_string($nodeOrText)) {
            $child = new self('#text', $nodeOrText, []);
            $child->parent = $this;
            $this->children[] = $child;
            return $this;
        }
        $nodeOrText->parent = $this;
        $this->children[]   = $nodeOrText;
        return $this;
    }

    /**
     * Append raw (unescaped) HTML to the current element.
     * Use with care. The content is inserted as-is.
     *
     * @param string $html Raw HTML string.
     *
     * @return static
     */
    public function raw(string $html): static
    {
        $child = new self('#raw', $html, []);
        $child->parent = $this;
        $this->children[] = $child;
        return $this;
    }

    /**
     * Set or remove an attribute. Names allow letters, digits, '_', '-', ':'.
     *
     * - If $value is null → the attribute is removed.
     * - If $value is an array → values are normalized to strings.
     *
     * @param string            $name  Attribute name.
     * @param string|array|null $value Single value or array of values, or null to remove.
     *
     * @return static
     *
     * @throws InvalidArgumentException If the attribute name is invalid.
     */
    public function attr(string $name, string|array|null $value = null): static
    {
        if ($name === '' || !preg_match('/^[a-zA-Z_][\w:\-]*$/', $name)) {
            throw new InvalidArgumentException("Invalid attribute name: '$name'");
        }
        if ($value === null) {
            unset($this->attributes[$name]);
            return $this;
        }

        $this->attributes[$name] = is_array($value)
            ? array_values(array_map('strval', $value))
            : [(string)$value];

        return $this;
    }

    /**
     * Set the "id" attribute.
     *
     * @param string $id ID value.
     *
     * @return static
     */
    public function id(string $id): static
    {
        return $this->attr('id', $id);
    }

    /**
     * Add CSS classes. Duplicates are removed automatically.
     *
     * @param string ...$class Class names.
     *
     * @return static
     */
    public function class(string ...$class): static
    {
        $current = $this->attributes['class'] ?? [];
        return $this->attr('class', array_values(array_unique([...$current, ...$class])));
    }

    /**
     * Set a data-* attribute, e.g. data('role','tab') → data-role="tab".
     *
     * @param string $key   Data key (will be lowercased and trimmed).
     * @param string $value Data value.
     *
     * @return static
     */
    public function data(string $key, string $value): static
    {
        $key = strtolower(trim($key));
        return $this->attr("data-$key", $value);
    }

    /**
     * Set an aria-* attribute, e.g. aria('label','Open menu') → aria-label="Open menu".
     *
     * @param string $key   ARIA key (will be lowercased and trimmed).
     * @param string $value ARIA value.
     *
     * @return static
     */
    public function aria(string $key, string $value): static
    {
        $key = strtolower(trim($key));
        return $this->attr("aria-$key", $value);
    }

    /**
     * Get the parent node.
     *
     * @return self|null Parent node or null if root.
     */
    public function parent(): ?self
    {
        return $this->parent;
    }

    /**
     * Get the first child node if any.
     *
     * @return self|null First child or null.
     */
    public function firstChild(): ?self
    {
        return $this->children[0] ?? null;
    }

    /**
     * Get the next sibling node if any.
     *
     * @return self|null Next sibling or null.
     */
    public function nextSibling(): ?self
    {
        if (!$this->parent) {
            return null;
        }
        $siblings = $this->parent->children;
        $i = array_search($this, $siblings, true);
        return ($i === false) ? null : ($siblings[$i + 1] ?? null);
    }

    /**
     * Deep clone the current node and its entire subtree.
     * The resulting clone is detached (parent = null).
     *
     * @return self A new, independent tree with the same structure and data.
     */
    public function cloneDeep(): self
    {
        $clone = new self($this->tag, $this->text, $this->attributes);
        foreach ($this->children as $child) {
            $childClone = $child->cloneDeep();
            $childClone->parent = $clone;
            $clone->children[]  = $childClone;
        }
        return $clone;
    }

    /**
     * Detach this node from its parent (if any), making it a stand-alone root.
     *
     * @return static
     */
    public function detach(): static
    {
        if ($this->parent) {
            $siblings = &$this->parent->children;
            $i = array_search($this, $siblings, true);
            if ($i !== false) {
                array_splice($siblings, $i, 1);
            }
            $this->parent = null;
        }
        return $this;
    }

    /**
     * Replace this node in its parent with another node.
     *
     * The replacement node is inserted at the same position, and its parent
     * is set to the original parent. The current node is detached.
     *
     * @param self $node Replacement node (will be attached to this node's parent).
     *
     * @return self The replacement node.
     *
     * @throws RuntimeException If this node has no parent.
     */
    public function replaceWith(self $node): self
    {
        if (!$this->parent) {
            throw new RuntimeException('Cannot replace root node.');
        }
        $parent = $this->parent;
        $siblings = &$parent->children;
        $i = array_search($this, $siblings, true);
        if ($i === false) {
            throw new RuntimeException('Inconsistent tree: node not found in parent.');
        }

        // detach replacement from its previous parent if any
        if ($node->parent) {
            $node->detach();
        }

        $node->parent = $parent;
        $siblings[$i] = $node;
        $this->parent = null;

        return $node;
    }

    /* -----------------------------------------------------------------
     * Rendering
     * ----------------------------------------------------------------- */

    /**
     * Cast to string – minified HTML rendering.
     *
     * @return string Minified HTML.
     */
    public function __toString(): string
    {
        return $this->render(minify: true);
    }

    /**
     * Render this node (and its subtree) to HTML.
     *
     * @param bool $minify      true → single-line/minified; false → pretty-print.
     * @param int  $indentLevel Current indent level for recursion (pretty mode).
     *
     * @return string HTML string.
     */
    public function render(bool $minify = false, int $indentLevel = 0): string
    {
        // Internal text node
        if ($this->tag === '#text') {
            $txt = htmlspecialchars($this->text ?? '', ENT_QUOTES | ENT_SUBSTITUTE);
            if ($minify) {
                return $txt;
            }
            $indent  = str_repeat(self::INDENT, $indentLevel);
            $newline = self::NL;
            return $indent . $txt . $newline;
        }

        // Internal raw node (UNESCAPED)
        if ($this->tag === '#raw') {
            $raw = (string)($this->text ?? '');
            if ($minify) {
                return $raw;
            }
            $indent  = str_repeat(self::INDENT, $indentLevel);
            $newline = self::NL;
            // try to keep formatting readable; raw may contain multiple lines
            $rawLines = explode("\n", $raw);
            foreach ($rawLines as &$line) {
                $line = $indent . rtrim($line, "\r");
            }
            return implode(self::NL, $rawLines) . $newline;
        }

        // --- minified path --------------------------------------------
        if ($minify) {
            $attr  = self::renderAttributes($this->attributes);
            $html  = "<{$this->tag}{$attr}>";

            if (!in_array($this->tag, self::VOID_ELEMENTS, true)) {
                $inner = htmlspecialchars($this->text ?? '', ENT_QUOTES | ENT_SUBSTITUTE);
                foreach ($this->children as $child) {
                    $inner .= $child->render(true);
                }
                $html .= "{$inner}</{$this->tag}>";
            }
            return $html;
        }

        // --- pretty-print branch --------------------------------------
        $indent      = str_repeat(self::INDENT, $indentLevel);
        $innerIndent = $indent . self::INDENT;
        $newline     = self::NL;

        // attributes
        $attr = self::renderAttributes($this->attributes);

        // void element
        if (in_array($this->tag, self::VOID_ELEMENTS, true)) {
            return "{$indent}<{$this->tag}{$attr}>{$newline}";
        }

        // collect inner lines: optional text + children
        $innerLines = [];

        if ($this->text !== null && $this->text !== '') {
            $innerLines[] = $innerIndent .
                            htmlspecialchars($this->text, ENT_QUOTES | ENT_SUBSTITUTE);
        }

        foreach ($this->children as $child) {
            $innerLines[] = rtrim($child->render(false, $indentLevel + 1), $newline);
        }

        // empty element
        if ($innerLines === []) {
            return "{$indent}<{$this->tag}{$attr}></{$this->tag}>{$newline}";
        }

        $inner = $newline . implode($newline, $innerLines) . $newline . $indent;
        return "{$indent}<{$this->tag}{$attr}>{$inner}</{$this->tag}>{$newline}";
    }

    /**
     * Convert attribute map to a string suitable for HTML.
     * - Attribute names and values are escaped with ENT_QUOTES|ENT_SUBSTITUTE.
     * - Boolean attributes in the form ['disabled'=>['disabled']] render as " disabled".
     *
     * @param array<string,string[]> $attributes Normalized attributes.
     *
     * @return string Leading-space-prefixed attribute string or empty string.
     */
    private static function renderAttributes(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $values) {
            $safeName = htmlspecialchars((string)$name, ENT_QUOTES | ENT_SUBSTITUTE);

            // boolean attribute shortcut if exactly [$name]
            if (count($values) === 1 && $values[0] === $name) {
                $out .= ' ' . $safeName;
                continue;
            }

            $safeValue = htmlspecialchars(implode(' ', array_map('strval', $values)), ENT_QUOTES | ENT_SUBSTITUTE);
            $out .= ' ' . $safeName . '="' . $safeValue . '"';
        }
        return $out;
    }

    /* -----------------------------------------------------------------
     * CSS-like querying
     * ----------------------------------------------------------------- */

    /**
     * Find descendants matching a (comma-separated) CSS selector subset.
     *
     * Supported selectors:
     *  - tag, *, #id, .class
     *  - [attr], [attr=value], [attr^=v], [attr$=v], [attr*=v]
     *  - combinators: ' ' (descendant), '>' (child), '+' (adjacent), '~' (sibling)
     *  - pseudo-classes: :first-child, :last-child, :nth-child(n|odd|even)
     *
     * @param string $selector Comma-separated selector list.
     *
     * @return self[] Matched nodes (deduplicated, original order preserved).
     */
    public function query(string $selector): array
    {
        return Selector::select($this, $selector);
    }

    /**
     * Find the first match for a selector or return null.
     *
     * @param string $selector Selector string.
     *
     * @return self|null First matched node or null.
     */
    public function queryOne(string $selector): ?self
    {
        $all = $this->query($selector);
        return $all[0] ?? null;
    }

    /* -----------------------------------------------------------------
     * Internal helpers
     * ----------------------------------------------------------------- */

    /**
     * Normalize user-provided attributes into list-of-strings form.
     * Integer keys are treated as boolean attributes (e.g. ['disabled']).
     *
     * @param array $input Raw attribute input.
     *
     * @return array<string,string[]> Normalized attribute map.
     */
    private static function normaliseAttributes(array $input): array
    {
        $out = [];
        foreach ($input as $key => $value) {
            if (is_int($key)) {
                $name = (string)$value;
                $out[$name] = [$name];
                continue;
            }
            $out[$key] = is_array($value)
                ? array_values(array_map('strval', $value))
                : [(string)$value];
        }
        return $out;
    }

    /**
     * Internal: get child nodes.
     *
     * @internal
     * @return self[]
     */
    public function _children(): array { return $this->children; }

    /**
     * Internal: get parent node.
     *
     * @internal
     * @return self|null
     */
    public function _parent(): ?self   { return $this->parent;   }

    /**
     * Internal: get normalized attributes.
     *
     * @internal
     * @return array<string,string[]>
     */
    public function _attr(): array     { return $this->attributes; }
}

/* ------------------------------------------------------------------ */
/* Selector – minimal CSS subset engine                               */
/* ------------------------------------------------------------------ */

/**
 * Class Selector
 *
 * Minimal CSS-like selector engine working over Phtmal trees.
 * Provides tokenization, tree walking with combinators, and matching logic.
 */
final class Selector
{
    /**
     * RegExp for a single "segment": optional combinator + simple selector.
     * Entire selector is a sequence of such segments.
     */
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

    /**
     * Attribute sub-pattern:
     *  [name], [name=value], [name^=v], [name$=v], [name*=v]
     * Value may be double-quoted, single-quoted, or unquoted.
     */
    private const ATTRIBUTE_PATTERN =
        '/\[\s*([\w:\-]+)\s*(?:(\^=|\$=|\*=|=)\s*(?:"([^"]*)"|\'([^\']*)\'|([^\]\s]+))\s*)?\]/';

    /**
     * Simple in-memory cache for tokenized selectors.
     *
     * @var array<string,array<int,array<string,mixed>>>
     */
    private static array $tokenCache = [];

    /**
     * Select all descendants of $root that match $selector.
     *
     * @param Phtmal $root     Root context.
     * @param string $selector Comma-separated selector list.
     *
     * @return Phtmal[] Matched nodes (deduplicated, original order).
     */
    public static function select(Phtmal $root, string $selector): array
    {
        $hits = [];
        foreach (array_map('trim', explode(',', $selector)) as $sequence) {
            if ($sequence === '') {
                continue;
            }
            $parts = self::tokenize($sequence);
            self::walk($root, $parts, $hits);
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
     * Tokenize selector into an array of parts.
     *
     * Each part:
     *  - 'tag'        => string|null
     *  - 'id'         => string|null
     *  - 'classes'    => string[]
     *  - 'attributes' => array{0:string,1:?string,2:?string}[]  [name, op, value]
     *  - 'pseudo'     => array{0:Pseudo,1:?string}|null
     *  - 'comb'       => Combinator
     *
     * @param string $selector Selector string.
     *
     * @return array<int,array<string,mixed>> Parsed parts.
     *
     * @throws InvalidArgumentException If selector is empty or unsupported.
     */
    private static function tokenize(string $selector): array
    {
        if ($selector === '') {
            throw new InvalidArgumentException('Empty selector');
        }

        if (isset(self::$tokenCache[$selector])) {
            return self::$tokenCache[$selector];
        }

        preg_match_all(self::SEGMENT_PATTERN, $selector, $matches, PREG_SET_ORDER);

        if (!$matches || implode('', array_column($matches, 0)) !== $selector) {
            throw new InvalidArgumentException("Unsupported selector: $selector");
        }

        // Remove empty segments, reindex, parse
        $segments = array_filter($matches, static fn($m) => trim($m[2]) !== '');
        $parts = array_values(array_map(
            static fn($seg) => self::parseSimple(
                trim($seg[2]),
                self::parseCombinator(trim($seg[1]))
            ),
            $segments
        ));

        return self::$tokenCache[$selector] = $parts;
    }

    /**
     * Map raw combinator string to enum value.
     *
     * @param string $raw Raw combinator substring.
     *
     * @return Combinator
     */
    private static function parseCombinator(string $raw): Combinator
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
     * @param string     $simple Simple selector fragment.
     * @param Combinator $comb   Combinator associated with this segment.
     *
     * @return array<string,mixed> Parsed part (see tokenize() for fields).
     */
    private static function parseSimple(string $simple, Combinator $comb): array
    {
        $tag = $id = null;  $classes = [];  $attributes = [];  $pseudo = null;

        if (preg_match('/^[a-z0-9]+|\*/i', $simple, $m)) { $tag = strtolower($m[0]); }
        if (preg_match('/\#([\w\-]+)/',    $simple, $m)) { $id  = $m[1]; }
        if (preg_match_all('/\.([\w\-]+)/', $simple, $m)) { $classes = $m[1]; }

        // attributes: [name], [name=value], [name^=v], [name$=v], [name*=v]
        if (preg_match_all(self::ATTRIBUTE_PATTERN, $simple, $m, PREG_SET_ORDER)) {
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
     * Depth-first traversal with combinator logic.
     *
     * @param Phtmal                              $context Current context node.
     * @param array<int,array<string,mixed>>      $parts   Remaining selector parts.
     * @param array<int,Phtmal>                   $hits    Output accumulator.
     *
     * @return void
     */
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
     * Get candidate nodes for the next segment based on a combinator.
     *
     * @param Phtmal     $context Current node.
     * @param Combinator $c       Combinator.
     *
     * @return Phtmal[] Candidate nodes.
     */
    private static function candidateNodes(Phtmal $context, Combinator $c): array
    {
        // direct children
        if ($c === Combinator::CHILD) {
            return $context->_children();
        }

        // for +, ~ we need siblings relative to the current node in its parent
        $parent = $context->_parent();
        if (!$parent) {
            return ($c === Combinator::DESCENDANT) ? self::descendants($context) : [];
        }

        $siblings = $parent->_children();
        $i = array_search($context, $siblings, true);

        return match ($c) {
            Combinator::ADJACENT   => ($i === false || !isset($siblings[$i+1])) ? [] : [$siblings[$i+1]],
            Combinator::SIBLING    => ($i === false) ? [] : array_slice($siblings, $i+1),
            Combinator::DESCENDANT => self::descendants($context),
            default                => [],
        };
    }

    /**
     * Pre-order DFS collecting all descendants (excluding the node itself).
     *
     * @param Phtmal $node Node to scan.
     *
     * @return Phtmal[] All descendant nodes.
     */
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

    /**
     * Check whether a single node matches a parsed selector part.
     *
     * @param Phtmal                   $node Node to match.
     * @param array<string,mixed>      $sel  Parsed selector part.
     *
     * @return bool True if the node matches.
     */
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
        foreach ($sel['attributes'] as [$key, $op, $expected]) {
            $has = array_key_exists($key, $node->_attr());
            if (!$has) {
                return false; // both [attr] and [attr<op>value] require presence
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
        if ($sel['pseudo'] && !self::matchPseudo($node, ...$sel['pseudo'])) {
            return false;
        }
        return true;
    }

    /**
     * Evaluate supported pseudo-classes for a node.
     *
     * @param Phtmal      $node  Node to evaluate.
     * @param Pseudo      $pseudo Pseudo kind.
     * @param string|null $arg   Argument for :nth-child.
     *
     * @return bool True if the pseudo condition holds.
     */
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

    /**
     * Check :nth-child argument.
     * Supports: integer n, "odd", "even".
     *
     * @param int|null    $index Zero-based index in siblings or null if not found.
     * @param string|null $arg   nth argument.
     *
     * @return bool True if the condition matches.
     */
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
    case LAST_CHILD = 'last';

    /** :nth-child() – supports integer n, "odd", "even". */
    case NTH_CHILD = 'nth';
}
