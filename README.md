# Phtmal

Tiny, fluent HTML node tree with a minimal CSS-like selector engine — built to be lightweight, readable, and extensible. Designed for future integration with a `layout` package (name TBD) and for use in server-side rendering scenarios.

> **Highlights**
>
> - Fluent builder via `__call()` (`$ul->li('A')->end()`), with optional subtree callbacks
> - Pretty vs minified rendering
> - Safe text nodes and explicit `raw()` nodes
> - Normalized attributes (boolean attrs supported)
> - Minimal CSS-like querying (tag, `*`, `#id`, `.class`, `[attr]`, combinators ` ` `>` `+` `~`, `:first-child`, `:last-child`, `:nth-child`)
> - Extensible: subclasses can override behavior and constants; interfaces define the contract

---

## Installation

Until the package name is finalized on Packagist, you can include it as a VCS/Path dependency:

**Path repository (local dev):**
```jsonc
{
  "repositories": [
    { "type": "path", "url": "../phtmal" }
  ],
  "require": {
    "concept-labs/phtmal": "*"
  }
}
```

**VCS repository (GitHub):**
```jsonc
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/Concept-Labs/phtmal" }
  ],
  "require": {
    "concept-labs/phtmal": "dev-main"
  }
}
```

Once published to Packagist, it will be as simple as:
```bash
composer require concept-labs/phtmal
```

---

## Quick start

```php
use Concept\Phtmal\Phtmal;

$html = (new Phtmal('ul'))
    ->li(['class' => 'item'], function (Phtmal $li) {
        $li->span('A');
    })
    ->li('B')->end()
->top();

echo $html->render();   // pretty
echo (string)$html;     // minified
```

**Attributes & boolean attributes:**
```php
$btn = (new Phtmal('button', 'Save'))
    ->class('btn', 'btn-primary')
    ->attr('disabled', ['disabled']); // short boolean form → renders as: <button disabled>…</button>
```

**Text vs raw HTML:**
```php
$div = (new Phtmal('div'))->text('Safe <b>text</b>'); // escaped
$div->raw('<b>UNSAFE</b>');                           // unescaped (use with care)
```

**Querying:**
```php
$items = $html->query('li.item:first-child, li.item:last-child');
$second = $html->queryOne('#main > .card:nth-child(2)');
```

---

## Interfaces

The library is interface-first. Documentation primarily lives on interfaces; implementations use `{@inheritDoc}`.

- `PhtmalNodeInterface` — node contract (fluent API, rendering, navigation, query integration).
- `SelectorInterface` — static querying: `select(PhtmalNodeInterface $root, string $selector): array`.

Key guarantees:
- Implementations **escape** text on render (except explicit `#raw` nodes).
- Attributes are normalized to **lists of strings**, enabling predictable rendering and boolean-shortcuts.
- Children order is **stable**.

---

## Core API (from `PhtmalNodeInterface`)

```php
// Builder & structure
__call(string $tag, array $args): PhtmalNodeInterface
end(): PhtmalNodeInterface
top(): PhtmalNodeInterface
append(PhtmalNodeInterface|string $nodeOrText): static
raw(string $html): static

// Content & attributes
text(?string $text): static
attr(string $name, string|array|null $value = null): static
id(string $id): static
class(string ...$class): static
data(string $key, string $value): static
aria(string $key, string $value): static

// Navigation & mutation
parent(): ?PhtmalNodeInterface
firstChild(): ?PhtmalNodeInterface
nextSibling(): ?PhtmalNodeInterface
cloneDeep(): PhtmalNodeInterface
detach(): static
replaceWith(PhtmalNodeInterface $node): PhtmalNodeInterface

// Rendering
render(bool $minify = false, int $indentLevel = 0): string

// Querying
query(string $selector): array
queryOne(string $selector): ?PhtmalNodeInterface

// Meta
getTag(): string
```

Notes:
- `__call('li', [...])` supports either `(text, attrs)` or `(attrs, callback)` — if a **callback** is the last argument, the method returns the **parent** (auto-jump back). Otherwise, it returns the new **child** (you can `->end()` manually).
- Boolean attributes are rendered in short form if normalized as `['disabled' => ['disabled']]`.

---

## Selector subset

Supported primitives:
- `tag`, `*`, `#id`, `.class`
- `[attr]`, `[attr=value]`, `[attr^=v]`, `[attr$=v]`, `[attr*=v]` (value can be quoted or unquoted)
- Combinators: descendant (` `), child (`>`), adjacent (`+`), sibling (`~`)
- Pseudos: `:first-child`, `:last-child`, `:nth-child(n|odd|even)`

> Adjacent (`+`) and general sibling (`~`) semantics are implemented relative to the **current matched node** (fixed from earlier versions that mistakenly inspected only the first child).


**Subclass example:**

```php
class XhtmlPhtmal extends \Concept\Phtmal\Phtmal
{
    protected const VOID_ELEMENTS = ['br', 'hr', 'img', 'meta', 'link'];

    protected function escape(string $text): string
    {
        // e.g. custom flags/charset or entity policy
        return htmlspecialchars($text, ENT_QUOTES | ENT_HTML401, 'UTF-8');
    }

    protected static function renderAttributes(array $attributes): string
    {
        // ensure deterministic attribute order (useful for testing)
        ksort($attributes);
        return parent::renderAttributes($attributes);
    }
}
```

---

## Layout package integration (preview idea)

Phtmal works nicely as a low-level DOM builder for the future `layout` package:

- **Composable blocks**: expose helpers that return `Phtmal` subtrees (`header()`, `footer()`, `card($title, $body)`).
- **Slots/partials**: pass callbacks into node builders to inject variable content.
- **Theming**: attach `class()`/`data()` policies at a single override point (subclass + `renderAttributes()` or decorators).
- **Safety**: keep everything escaped by default; allow `raw()` **only** for trusted HTML fragments.

---

## Performance tips

- Selector tokenization is cached in-memory; reusing the same selector string is cheap.
- For very large trees:
  - Build once, update in place (e.g., `text()`/`replaceWith()`), then render.
  - Consider indexing nodes by id/class during construction if you do heavy querying.
- Rendering is streaming-recursive; depth-first and quite fast for typical UI trees.

---

## TODO
- Optional `an+b` syntax for `:nth-child`.
- Optional `queryAll()->iterator` for lazy traversal.
- Potential `toArray()` / `fromArray()` serialization helpers.
- Pluggable selector engine (via `SelectorInterface`) for advanced use-cases.

---

## License

Apache-2.0
