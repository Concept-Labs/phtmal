<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use InvalidArgumentException;
use RuntimeException;

/**
 * {@inheritDoc}
 */
class Phtmal implements PhtmalNodeInterface
{
    /* -----------------------------------------------------------------
     * Configuration constants (overridable in subclasses)
     * ----------------------------------------------------------------- */

    /**
     * {@inheritDoc}
     * @var string[]
     */
    protected const VOID_ELEMENTS = [
        'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
        'link', 'meta', 'param', 'source', 'track', 'wbr',
    ];

    /** @var string */
    protected const INDENT = '  ';

    /** @var string */
    protected const NL = "\n";

    /* -----------------------------------------------------------------
     * Internal state (protected for extensibility)
     * ----------------------------------------------------------------- */

    /**
     * @var PhtmalNodeInterface|null
     */
    protected ?PhtmalNodeInterface $parent = null;

    /**
     * @var PhtmalNodeInterface[]
     */
    protected array $children = [];

    /** @var string */
    protected string $tag;

    /**
     * @var array<string,string[]>
     */
    protected array $attributes = [];

    /** @var string|null */
    protected ?string $text;

    /* -----------------------------------------------------------------
     * Construction
     * ----------------------------------------------------------------- */

    /**
     * {@inheritDoc}
     *
     * @param string $tag
     * @param string|null $text
     * @param array $attr
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
        $this->attributes = static::normaliseAttributes($attr);
    }

    /* -----------------------------------------------------------------
     * Factory/escape hooks (extensibility points)
     * ----------------------------------------------------------------- */

    /**
     * Create a new node instance of the current class (late static).
     *
     * @param string      $tag
     * @param string|null $text
     * @param array       $attr
     * @return static
     */
    protected function newNode(string $tag, ?string $text = null, array $attr = []): static
    {
        /** @var static $node */
        $node = new static($tag, $text, $attr);
        return $node;
    }

    /**
     * Escape text for safe HTML output. Subclasses may override.
     *
     * @param string $text
     * @return string
     */
    protected function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    }

    /* -----------------------------------------------------------------
     * Fluent builder
     * ----------------------------------------------------------------- */

    /** {@inheritDoc} */
    public function __call(string $tag, array $args): PhtmalNodeInterface
    {
        $callback = null;
        if ($args && is_callable(end($args))) {
            $callback = array_pop($args);
        }

        [$text, $attr] = $args + [null, []];

        $child = $this->newNode(
            $tag,
            is_string($text) ? $text : null,
            is_array($text)  ? $text : $attr
        );
        $child->parent     = $this;
        $this->children[]  = $child;

        if ($callback) {
            $callback($child);
            return $this;
        }
        return $child;
    }

    /** {@inheritDoc} */
    public function end(): PhtmalNodeInterface
    {
        return $this->parent
            ?? throw new RuntimeException('Already at root.');
    }

    /** {@inheritDoc} */
    public function top(): PhtmalNodeInterface
    {
        $node = $this;
        while ($node->parent) { $node = $node->parent; }
        return $node;
    }

    /* -----------------------------------------------------------------
     * Convenience API
     * ----------------------------------------------------------------- */

    /** {@inheritDoc} */
    public function text(?string $text): static
    {
        $this->text = $text;
        return $this;
    }

    /** {@inheritDoc} */
    public function append(PhtmalNodeInterface|string $nodeOrText): static
    {
        if (is_string($nodeOrText)) {
            $child = $this->newNode('#text', $nodeOrText, []);
            $child->parent = $this;
            $this->children[] = $child;
            return $this;
        }
        // attach an existing node
        if ($nodeOrText->parent() !== null) {
            $nodeOrText->detach();
        }
        // NOTE: we assume it’s the same implementation tree
        /** @var static $nodeOrText */
        $nodeOrTextParentProp = $nodeOrText;
        $nodeOrTextParentProp->setParent($this);
        $this->children[]   = $nodeOrText;
        return $this;
    }

    /**
     * Set parent (internal helper for attach). Exposed protected for subclassing.
     *
     * @param PhtmalNodeInterface|null $parent
     * @return void
     */
    protected function setParent(?PhtmalNodeInterface $parent): void
    {
        $this->parent = $parent;
    }

    /** {@inheritDoc} */
    public function raw(string $html): static
    {
        $child = $this->newNode('#raw', $html, []);
        $child->parent = $this;
        $this->children[] = $child;
        return $this;
    }

    /** {@inheritDoc} */
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

    /** {@inheritDoc} */
    public function id(string $id): static
    {
        return $this->attr('id', $id);
    }

    /** {@inheritDoc} */
    public function class(string ...$class): static
    {
        $current = $this->attributes['class'] ?? [];
        return $this->attr('class', array_values(array_unique([...$current, ...$class])));
    }

    /** {@inheritDoc} */
    public function data(string $key, string $value): static
    {
        $key = strtolower(trim($key));
        return $this->attr("data-$key", $value);
    }

    /** {@inheritDoc} */
    public function aria(string $key, string $value): static
    {
        $key = strtolower(trim($key));
        return $this->attr("aria-$key", $value);
    }

    /** {@inheritDoc} */
    public function parent(): ?PhtmalNodeInterface
    {
        return $this->parent;
    }

    /** {@inheritDoc} */
    public function firstChild(): ?PhtmalNodeInterface
    {
        return $this->children[0] ?? null;
    }

    /** {@inheritDoc} */
    public function nextSibling(): ?PhtmalNodeInterface
    {
        if (!$this->parent) {
            return null;
        }
        $siblings = $this->parent->_children();
        $i = array_search($this, $siblings, true);
        return ($i === false) ? null : ($siblings[$i + 1] ?? null);
    }

    /** {@inheritDoc} */
    public function cloneDeep(): PhtmalNodeInterface
    {
        $clone = $this->newNode($this->tag, $this->text, $this->attributes);
        foreach ($this->children as $child) {
            $childClone = $child->cloneDeep();
            if ($childClone instanceof self) {
                $childClone->setParent($clone);
            } else {
                // Best effort: attach if implementation provides a setParent()
                if (method_exists($childClone, 'setParent')) {
                    $childClone->setParent($clone);
                }
            }
            $clone->children[]  = $childClone;
        }
        return $clone;
    }

    /** {@inheritDoc} */
    public function detach(): static
    {
        if ($this->parent) {
            $siblings = &$this->parent-> _childrenRef();
            $i = array_search($this, $siblings, true);
            if ($i !== false) {
                array_splice($siblings, $i, 1);
            }
            $this->parent = null;
        }
        return $this;
    }

    /**
     * INTERNAL: reference to children list for in-place operations.
     * Subclasses may override storage strategy.
     *
     * @internal
     * @return array<int,PhtmalNodeInterface>
     */
    protected function &_childrenRef(): array
    {
        return $this->children;
    }

    /** {@inheritDoc} */
    public function replaceWith(PhtmalNodeInterface $node): PhtmalNodeInterface
    {
        if (!$this->parent) {
            throw new RuntimeException('Cannot replace root node.');
        }
        $parent = $this->parent;
        $siblings = &$this->parent->_childrenRef();
        $i = array_search($this, $siblings, true);
        if ($i === false) {
            throw new RuntimeException('Inconsistent tree: node not found in parent.');
        }

        if ($node->parent() !== null) {
            $node->detach();
        }

        if ($node instanceof self) {
            $node->setParent($parent);
        } elseif (method_exists($node, 'setParent')) {
            $node->setParent($parent);
        }

        $siblings[$i] = $node;
        $this->parent = null;

        return $node;
    }

    /* -----------------------------------------------------------------
     * Rendering
     * ----------------------------------------------------------------- */

    /** {@inheritDoc} */
    public function __toString(): string
    {
        return $this->render(minify: true);
    }

    /** {@inheritDoc} */
    public function render(bool $minify = false, int $indentLevel = 0): string
    {
        // Internal text node
        if ($this->tag === '#text') {
            $txt = $this->escape($this->text ?? '');
            if ($minify) {
                return $txt;
            }
            $indent  = str_repeat(static::INDENT, $indentLevel);
            $newline = static::NL;
            return $indent . $txt . $newline;
        }

        // Internal raw node (UNESCAPED)
        if ($this->tag === '#raw') {
            $raw = (string)($this->text ?? '');
            if ($minify) {
                return $raw;
            }
            $indent  = str_repeat(static::INDENT, $indentLevel);
            $newline = static::NL;
            $rawLines = explode("\n", $raw);
            foreach ($rawLines as &$line) {
                $line = $indent . rtrim($line, "\r");
            }
            return implode(static::NL, $rawLines) . $newline;
        }

        // --- minified path --------------------------------------------
        if ($minify) {
            $attr  = static::renderAttributes($this->attributes);
            $html  = "<{$this->tag}{$attr}>";

            if (!in_array($this->tag, static::VOID_ELEMENTS, true)) {
                $inner = $this->escape($this->text ?? '');
                foreach ($this->children as $child) {
                    $inner .= $child->render(true);
                }
                $html .= "{$inner}</{$this->tag}>";
            }
            return $html;
        }

        // --- pretty-print branch --------------------------------------
        $indent      = str_repeat(static::INDENT, $indentLevel);
        $innerIndent = $indent . static::INDENT;
        $newline     = static::NL;

        $attr = static::renderAttributes($this->attributes);

        if (in_array($this->tag, static::VOID_ELEMENTS, true)) {
            return "{$indent}<{$this->tag}{$attr}>{$newline}";
        }

        $innerLines = [];

        if ($this->text !== null && $this->text !== '') {
            $innerLines[] = $innerIndent . $this->escape($this->text);
        }

        foreach ($this->children as $child) {
            $innerLines[] = rtrim($child->render(false, $indentLevel + 1), $newline);
        }

        if ($innerLines === []) {
            return "{$indent}<{$this->tag}{$attr}></{$this->tag}>{$newline}";
        }

        $inner = $newline . implode($newline, $innerLines) . $newline . $indent;
        return "{$indent}<{$this->tag}{$attr}>{$inner}</{$this->tag}>{$newline}";
    }

    /**
     * Convert attribute map to a leading-space-prefixed string.
     *
     * @param array<string,string[]> $attributes
     * @return string
     */
    protected static function renderAttributes(array $attributes): string
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
     * Query
     * ----------------------------------------------------------------- */

    /** {@inheritDoc} */
    public function query(string $selector): array
    {
        return Selector::select($this, $selector);
    }

    /** {@inheritDoc} */
    public function queryOne(string $selector): ?PhtmalNodeInterface
    {
        $all = $this->query($selector);
        return $all[0] ?? null;
    }

    /* -----------------------------------------------------------------
     * Internals
     * ----------------------------------------------------------------- */

    /**
     * Normalize attributes into list-of-strings form.
     *
     * @param array $input
     * @return array<string,string[]>
     */
    protected static function normaliseAttributes(array $input): array
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

    /** {@inheritDoc} */
    public function _children(): array { return $this->children; }

    /** {@inheritDoc} */
    public function _parent(): ?PhtmalNodeInterface { return $this->parent; }

    /** {@inheritDoc} */
    public function _attr(): array { return $this->attributes; }

    /** {@inheritDoc} */
    public function getTag(): string { return $this->tag; }
}