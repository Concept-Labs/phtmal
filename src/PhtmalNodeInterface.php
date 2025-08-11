<?php
declare(strict_types=1);

namespace Concept\Phtmal;

/**
 * Interface PhtmalNodeInterface
 *
 * Contract for a fluent, extensible HTML-like node tree with:
 *  - pretty/minified rendering,
 *  - attribute normalization and helpers (id/class/data/aria),
 *  - text and raw (unescaped) sub-nodes,
 *  - minimal CSS-like query engine integration.
 *
 * Implementations SHOULD:
 *  - keep children order-stable,
 *  - escape text content on render (except for raw nodes),
 *  - support boolean attributes via single-value list ['disabled' => ['disabled']].
 */
interface PhtmalNodeInterface
{
    /**
     * Magic child-builder (e.g. $ul->li('text', ['class'=>'x'])).
     *
     * Behavior:
     *  - if the last argument is callable → treated as a subtree builder,
     *    and this method MUST return the current node (parent),
     *  - otherwise MUST return the newly created child node.
     *
     * @param string $tag  Child tag name.
     * @param array  $args [0] => string|array|null $textOrAttrs, [1] => array $attrs, [last] => ?callable
     *
     * @return PhtmalNodeInterface Child or parent depending on presence of callback.
     */
    public function __call(string $tag, array $args): PhtmalNodeInterface;

    /**
     * Finish the current element and return to its parent.
     *
     * @return PhtmalNodeInterface
     */
    public function end(): PhtmalNodeInterface;

    /**
     * Jump to the root element of the current tree.
     *
     * @return PhtmalNodeInterface
     */
    public function top(): PhtmalNodeInterface;

    /**
     * Set or clear text content.
     *
     * @param string|null $text New content or null to clear.
     *
     * @return static
     */
    public function text(?string $text): static;

    /**
     * Append a child node or a text node. Strings MUST be appended as text nodes.
     *
     * @param PhtmalNodeInterface|string $nodeOrText Node or plain text.
     *
     * @return static
     */
    public function append(PhtmalNodeInterface|string $nodeOrText): static;

    /**
     * Append raw (UNESCAPED) HTML to the current element.
     *
     * @param string $html Raw HTML string.
     *
     * @return static
     */
    public function raw(string $html): static;

    /**
     * Set or remove an attribute. Names MAY include letters, digits, '_', '-', ':'.
     *
     * - If $value is null → attribute MUST be removed.
     * - If $value is array → values MUST be normalized to list of strings.
     *
     * @param string            $name
     * @param string|array|null $value
     *
     * @return static
     */
    public function attr(string $name, string|array|null $value = null): static;

    /**
     * Convenience: set 'id' attribute.
     *
     * @param string $id
     * @return static
     */
    public function id(string $id): static;

    /**
     * Convenience: add one or more CSS classes, deduplicated.
     *
     * @param string ...$class
     * @return static
     */
    public function class(string ...$class): static;

    /**
     * Convenience: set data-* attribute (data-{$key}="{$value}").
     *
     * @param string $key   Lower-cased by implementation.
     * @param string $value
     * @return static
     */
    public function data(string $key, string $value): static;

    /**
     * Convenience: set aria-* attribute (aria-{$key}="{$value}").
     *
     * @param string $key   Lower-cased by implementation.
     * @param string $value
     * @return static
     */
    public function aria(string $key, string $value): static;

    /**
     * Get the parent node or null if root.
     *
     * @return PhtmalNodeInterface|null
     */
    public function parent(): ?PhtmalNodeInterface;

    /**
     * Get the first child node if any.
     *
     * @return PhtmalNodeInterface|null
     */
    public function firstChild(): ?PhtmalNodeInterface;

    /**
     * Get the next sibling node if any.
     *
     * @return PhtmalNodeInterface|null
     */
    public function nextSibling(): ?PhtmalNodeInterface;

    /**
     * Deep clone this node and its entire subtree. Returned clone MUST be detached.
     *
     * @return PhtmalNodeInterface
     */
    public function cloneDeep(): PhtmalNodeInterface;

    /**
     * Detach this node from its parent (if any), making it a stand-alone root.
     *
     * @return static
     */
    public function detach(): static;

    /**
     * Replace this node in its parent with another node.
     *
     * @param PhtmalNodeInterface $node Replacement node.
     * @return PhtmalNodeInterface The replacement node (now attached).
     */
    public function replaceWith(PhtmalNodeInterface $node): PhtmalNodeInterface;

    /**
     * Render this node (and its subtree) to HTML.
     *
     * @param bool $minify      true → single-line/minified; false → pretty-print.
     * @param int  $indentLevel Current indent level for recursion (pretty mode).
     *
     * @return string
     */
    public function render(bool $minify = false, int $indentLevel = 0): string;

    /**
     * Query descendants using a minimal CSS-like selector subset.
     *
     * Supported:
     *  - tag, *, #id, .class
     *  - [attr], [attr=value], [attr^=v], [attr$=v], [attr*=v]
     *  - combinators: ' ' (descendant), '>' (child), '+' (adjacent), '~' (sibling)
     *  - :first-child, :last-child, :nth-child(n|odd|even)
     *
     * @param string $selector
     * @return PhtmalNodeInterface[] Matched nodes in DOM order (deduplicated).
     */
    public function query(string $selector): array;

    /**
     * Query and return the first match or null.
     *
     * @param string $selector
     * @return PhtmalNodeInterface|null
     */
    public function queryOne(string $selector): ?PhtmalNodeInterface;

    /**
     * Node tag accessor (lower-cased tag or internal marker like "#text"/"#raw").
     *
     * @return string
     */
    public function getTag(): string;

    /**
     * INTERNAL: return children list.
     *
     * @internal
     * @return PhtmalNodeInterface[]
     */
    public function _children(): array;

    /**
     * INTERNAL: return parent node or null.
     *
     * @internal
     * @return PhtmalNodeInterface|null
     */
    public function _parent(): ?PhtmalNodeInterface;

    /**
     * INTERNAL: return normalized attribute map.
     *
     * @internal
     * @return array<string,string[]>
     */
    public function _attr(): array;
}
