<?php
declare(strict_types=1);

namespace Concept\Phtmal;

use DOMCdataSection;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Class DomHtmlParser
 *
 * {@inheritDoc}
 *
 * A tolerant HTML parser built on top of PHP's DOMDocument. It can parse entire
 * documents or fragments and convert them into a Phtmal tree. Script/style
 * contents are imported as RAW nodes to avoid escaping during rendering.
 */
class DomHtmlParser implements HtmlParserInterface
{
    /**
     * @var callable(string, ?string, array): PhtmalNodeInterface
     */
    protected $factory;

    /**
     * Default options.
     *
     * @var array{
     *   dropComments: bool,
     *   preserveWhitespace: bool,
     *   preservePreWhitespace: bool,
     *   encoding: string,
     *   rawTextTags: string[]
     * }
     */
    protected array $defaults = [
        'dropComments'          => true,
        'preserveWhitespace'    => false,
        'preservePreWhitespace' => true,
        'encoding'              => 'UTF-8',
        'rawTextTags'           => ['script','style'],
    ];

    /**
     * @param callable|null $factory Factory to create nodes: fn(string $tag, ?string $text, array $attr): PhtmalNodeInterface
     */
    public function __construct(?callable $factory = null)
    {
        $this->factory = $factory ?? static fn(string $tag, ?string $text, array $attr): PhtmalNodeInterface
            => new Phtmal($tag, $text, $attr);
    }

    /** {@inheritDoc} */
    public function parseDocument(string $html, array $options = []): PhtmalNodeInterface
    {
        $opt = $this->mergeOptions($options);
        $dom = $this->loadHtmlDocument($html, $opt, /*fragment*/ false);

        // DocumentElement is typically <html>
        $rootEl = $dom->documentElement ?: $dom->createElement('html');
        return $this->importElement($rootEl, $opt);
    }

    /** {@inheritDoc} */
    public function parseFragment(string $html, string $containerTag = 'div', array $options = []): PhtmalNodeInterface
    {
        $opt = $this->mergeOptions($options);
        $dom = $this->loadHtmlDocument($html, $opt, /*fragment*/ true);

        /** @var PhtmalNodeInterface $container */
        $container = ($this->factory)($containerTag, null, []);

        // Iterate top-level nodes of the fragment and import into container
        for ($n = $dom->firstChild; $n !== null; $n = $n->nextSibling) {
            if ($node = $this->importNode($n, $opt, /*parentTag*/ strtolower($containerTag))) {
                $container->append($node);
            }
        }
        return $container;
    }

    /**
     * Merge user options with defaults.
     *
     * @param array $options
     * @return array
     */
    protected function mergeOptions(array $options): array
    {
        return $options + $this->defaults;
    }

    /**
     * Load HTML (document or fragment) into DOMDocument with sane flags.
     *
     * @param string $html
     * @param array  $opt
     * @param bool   $fragment
     * @return DOMDocument
     */
    protected function loadHtmlDocument(string $html, array $opt, bool $fragment): DOMDocument
    {
        $dom = new DOMDocument('1.0', $opt['encoding'] ?? 'UTF-8');
        $dom->preserveWhiteSpace = true;
        $dom->formatOutput = false;

        $flags = LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT;
        if ($fragment) {
            // No implied <html>/<body>, treat string as-is
            $flags |= LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD;
        }

        // Ensure encoding is respected.
        $payload = '<meta http-equiv="Content-Type" content="text/html; charset=' . ($opt['encoding'] ?? 'UTF-8') . '">' . $html;

        // Suppress warnings for broken HTML; tolerant parsing.
        @$dom->loadHTML($payload, $flags);

        return $dom;
    }

    /**
     * Import a DOMElement recursively into a Phtmal node.
     *
     * @param DOMElement $el
     * @param array      $opt
     * @return PhtmalNodeInterface
     */
    protected function importElement(DOMElement $el, array $opt): PhtmalNodeInterface
    {
        $tag = strtolower($el->tagName);
        /** @var PhtmalNodeInterface $node */
        $node = ($this->factory)($tag, null, []);

        // attributes
        if ($el->hasAttributes()) {
            foreach ($el->attributes as $attr) {
                $name  = strtolower($attr->nodeName);
                $value = $attr->nodeValue ?? '';

                // boolean attribute: empty value or equals name
                if ($value === '' || strtolower($value) === $name) {
                    $node->attr($name, [$name]);
                } else {
                    $node->attr($name, (string)$value);
                }
            }
        }

        // children
        $rawContext = in_array($tag, $opt['rawTextTags'], true);
        if ($el->hasChildNodes()) {
            foreach ($el->childNodes as $child) {
                if ($imported = $this->importNode($child, $opt, $tag, $rawContext)) {
                    $node->append($imported);
                }
            }
        }

        return $node;
    }

    /**
     * Import an arbitrary DOMNode.
     *
     * @param DOMNode $n
     * @param array   $opt
     * @param string  $parentTag
     * @param bool    $rawContext
     * @return PhtmalNodeInterface|null
     */
    protected function importNode(DOMNode $n, array $opt, string $parentTag = 'div', bool $rawContext = false): ?PhtmalNodeInterface
    {
        switch ($n->nodeType) {
            case XML_ELEMENT_NODE:
                /** @var DOMElement $n */
                return $this->importElement($n, $opt);

            case XML_TEXT_NODE:
                /** @var DOMText $n */
                $text = $n->nodeValue ?? '';

                // Skip whitespace-only text if not preserving, except in pre/textarea
                $isWhitespaceOnly = (trim($text) === '');
                $preLike = in_array(strtolower($parentTag), ['pre', 'textarea'], true);
                if ($isWhitespaceOnly && !$opt['preserveWhitespace'] && !($opt['preservePreWhitespace'] && $preLike)) {
                    return null;
                }

                if ($rawContext) {
                    // In <script>/<style> treat as RAW
                    /** @var PhtmalNodeInterface $raw */
                    $raw = ($this->factory)('#raw', $text, []);
                    return $raw;
                }

                /** @var PhtmalNodeInterface $txt */
                $txt = ($this->factory)('#text', $text, []);
                return $txt;

            case XML_CDATA_SECTION_NODE:
                /** @var DOMCdataSection $n */
                $data = $n->data ?? '';
                if ($rawContext) {
                    return ($this->factory)('#raw', $data, []);
                }
                return ($this->factory)('#text', $data, []);

            case XML_COMMENT_NODE:
                if ($opt['dropComments']) {
                    return null;
                }
                // Comments are not supported in Phtmal tree; drop by default.
                return null;

            default:
                // Ignore processing instructions, etc.
                return null;
        }
    }
}
