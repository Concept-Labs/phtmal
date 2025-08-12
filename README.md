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
> - **NEW:** Parse raw HTML into a Phtmal tree via `HtmlParserInterface` + `DomHtmlParser`

---

## Installation

Once published to Packagist:
```bash
composer require concept-labs/phtmal
```

For local/VCS use meanwhile:

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
    ->attr('disabled', ['disabled']); // short boolean form → <button disabled>…</button>
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

## HTML parsing (NEW)

You can parse raw HTML (documents or fragments) into a `Phtmal` tree using the parser interface.

### Interfaces
- `HtmlParserInterface` — contract:
  - `parseDocument(string $html, array $options = []): PhtmalNodeInterface`
  - `parseFragment(string $html, string $containerTag = 'div', array $options = []): PhtmalNodeInterface`
- `DomHtmlParser` — DOMDocument-based implementation (tolerant to malformed HTML).

### Usage
**Parse a full document:**
```php
use Concept\Phtmal\DomHtmlParser;

$parser = new DomHtmlParser();
$root = $parser->parseDocument('<!doctype html><html><body><div id="x">t</div></body></html>');

// $root is the <html> node
echo $root->render();       // pretty
echo (string)$root;         // minified
```

**Parse a fragment (no implied `<html>/<body>`):**
```php
$parser = new DomHtmlParser();
$list = $parser->parseFragment('<li>A</li><li class="x">B</li>', 'ul');

echo (string)$list; // <ul><li>A</li><li class="x">B</li></ul>
```

**Scripts/styles are imported as RAW nodes (not escaped):**
```php
$parser = new DomHtmlParser();
$div = $parser->parseFragment('<script>if (a < b) { alert("x"); }</script>', 'div');
echo (string)$div;
// <div><script>if (a < b) { alert("x"); }</script></div>
```

**Custom factory (use your subclass of Phtmal):**
```php
class MyNode extends Concept\Phtmal\Phtmal {}
$parser = new DomHtmlParser(fn(string $tag, ?string $text, array $attr) => new MyNode($tag, $text, $attr));
$tree = $parser->parseFragment('<span>Hello</span>', 'div');
```

### Options
`parseDocument()` and `parseFragment()` accept the same `$options` array:

| Option | Type | Default | Description |
|---|---|---:|---|
| `dropComments` | `bool` | `true` | Drop HTML comments. |
| `preserveWhitespace` | `bool` | `false` | Keep whitespace-only text nodes outside `<pre>/<textarea>`. |
| `preservePreWhitespace` | `bool` | `true` | Preserve whitespace in `<pre>` / `<textarea>`. |
| `encoding` | `string` | `'UTF-8'` | Input encoding hint for DOMDocument. |
| `rawTextTags` | `string[]` | `['script','style']` | Treat content of these tags as RAW (unescaped). |

---

## Interfaces (core)

The library is interface-first. Documentation primarily lives on interfaces; implementations use `{@inheritDoc}`.

- `PhtmalNodeInterface` — node contract (fluent API, rendering, navigation, query integration).
- `SelectorInterface` — static querying: `select(PhtmalNodeInterface $root, string $selector): array`.
- `HtmlParserInterface` — parse raw HTML into a Phtmal tree.

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

## Extensibility recommendations

- Overridable constants (protected): `VOID_ELEMENTS`, `INDENT`, `NL`.
- Overridable hooks (protected): `newNode()`, `escape()`, `renderAttributes()`, `_childrenRef()`.
- Open state (protected): `parent`, `children`, `tag`, `attributes`, `text`.

---

## Testing & QA

Install dev tools:
```bash
composer require --dev phpunit/phpunit:^10 phpstan/phpstan:^1.11
```

Run tests and static analysis:
```bash
vendor/bin/phpunit
vendor/bin/phpstan analyse
```

---

## License

MIT
