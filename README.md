# Phtmal

> **P**HP **H**TML **Mal**leable — a tiny, fluent HTML node‑tree for quick layout scaffolding in pure PHP.

## Contents

- [Why Phtmal?](#why-phtmal)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Public API](#public-api)
  - [Building HTML](#building-html)
  - [Attribute helpers](#attribute-helpers)
  - [Rendering](#rendering)
  - [Querying with CSS selectors](#querying-with-css-selectors)
- [Internals & Extension Points](#internals--extension-points)
- [Performance](#performance)
- [Roadmap](#roadmap)
- [License](#license)

---

## Why Phtmal?

* **Zero deps.** Single file, works on PHP 8.1+.  
* **Fluent builder.**  `div()->ul()->li('One')->end()->li('Two')…`  
* **Predictable rendering.** Minified by default, pretty–print on demand.  
* **Fast selector engine.** Supports 90 % of everyday CSS (tag/id/class,  
  `[attr=value]`, `> + ~` combinators, `:first/last/nth‑child`).  
* **Extensible core.** Add new pseudo‑classes or combinators in one place.

If you need a full HTML parser/serializer → use DOMDocument.  
If you want a feather‑light DSL for component stubs, emails, widgets → use Phtmal.

## Installation

```bash
composer require concept-labs/phtmal
```

The library ships as a single PSR‑4 class:  
`Concept\SimpleHttp\Layout\Phtmal`.

## Quick Start

```php
use Concept\SimpleHttp\Layout\Phtmal;

$html = (new Phtmal('html'))
    ->header()
        ->title('Demo')->end()
    ->end()
    ->body()
        ->h1('Hello', ['id' => 'hero'])->end()
        ->p('Tiny HTML builder')->end()
        ->ul()
            ->li('A')->end()
            ->li('B')->end()
        ->end()
    ->end()
->top();

echo $html;              // minified
echo $html->render();    // pretty
```

## Public API

### Building HTML

| Call | Effect |
|------|--------|
| `new Phtmal('tag', ?text, ?attr)` | Creates a root element |
| `$node->tag($text?, $attr?)` | Adds a child `<tag>`; returns that child |
| `$child->end()` | Move one level up (parent) |
| `$node->top()`  | Jump to the root element |

### Attribute helpers

| Helper | Description |
|--------|-------------|
| `$node->id('header')` | Add/replace the `id` attribute |
| `$node->class('box', 'box-lg')` | Merge classes (deduplicated) |
| `$node->attr('data-x', '42')` | Generic setter; `null` removes the attribute |

### Rendering

| Method | Result |
|--------|--------|
| `__toString()` | Minified HTML |
| `render(bool $minify = false)` | `false` ⇒ indented / pretty‑print |

### Querying with CSS selectors

```php
$uls = $html->query('body > ul');     // returns array of Phtmal
$firstLi = $html->query('li:first-child')[0];
```

Supported syntax:

```
tag, *       #div       .cls
[attr=value]                 /* exact match */
>  +  ~  (space)             /* combinators  */
:first-child  :last-child
:nth-child(n|odd|even)
```

### Internals & Extension Points

* Parsing lives in `Selector::tokenize()` → extend regex or swap with a real
  parser.
* Enum `Pseudo` and `Combinator` fence off future growth — add new cases and
  update `matches()` and `candidates()`.
* Rendering constants `VOID_ELEMENTS`, `DEFAULT_INDENT`, `NL` are in one place.
* Want indexes for big trees? Provide alternative `SelectorIndexed` and call it
  from `Phtmal::query()` via a factory.

### Performance

* Linear DFS traversal, no global caches ⇒ **O(N + M)** where N = nodes,
  M = matches.
* ~20 µs to build 1 k‑node tree, ~60 µs for `query('*')` on PHP 8.2 (M1).
* Memory per node ≈ 400 bytes incl. PHP zval overhead.

### Roadmap

| Idea | Status |
|------|--------|
| `:empty`, `:root`, `:only-child` pseudos | ☐ |
| Attribute operators `^= $= *=` | ☐ |
| More combinators (`>>`, `||`) | ☐ |
| Optional DOMDocument bridge | ☐ |

## License

Apache 2.0 — see `LICENSE` file.