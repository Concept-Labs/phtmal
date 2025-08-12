<?php
declare(strict_types=1);

namespace Concept\Phtmal;

/**
 * Interface HtmlParserInterface
 *
 * Contract for parsing raw HTML strings into a Phtmal node tree.
 * Implementations SHOULD be tolerant to malformed HTML and
 * SHOULD provide options for fragment parsing and whitespace handling.
 */
interface HtmlParserInterface
{
    /**
     * Parse a COMPLETE HTML document (may include <!doctype>, <html>, <head>, <body>).
     * Implementations SHOULD return the <html> element as the root node.
     *
     * @param string $html  Raw HTML document (UTF-8 expected).
     * @param array  $options {
     *   @var bool   $dropComments            Drop HTML comments (default: true)
     *   @var bool   $preserveWhitespace      Preserve whitespace-only text nodes (default: false)
     *   @var bool   $preservePreWhitespace   Preserve whitespace in <pre>, <textarea> (default: true)
     *   @var string $encoding                Input encoding (default: 'UTF-8')
     *   @var array<string> $rawTextTags      Tags whose text content MUST be treated as raw (default: ['script','style'])
     * }
     *
     * @return PhtmalNodeInterface Parsed root node (typically the <html> element).
     */
    public function parseDocument(string $html, array $options = []): PhtmalNodeInterface;

    /**
     * Parse an HTML FRAGMENT (no implied <html>/<body>), wrapping all top-level nodes
     * into a container element of the given tag.
     *
     * @param string $html           Raw HTML fragment.
     * @param string $containerTag   Container tag to host the fragment (default: 'div').
     * @param array  $options        See parseDocument() for the same options.
     *
     * @return PhtmalNodeInterface Container node with fragment children.
     */
    public function parseFragment(string $html, string $containerTag = 'div', array $options = []): PhtmalNodeInterface;
}
