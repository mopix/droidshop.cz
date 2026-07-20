<?php

namespace App\Core\Html;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Cleans tenant-authored HTML down to a whitelist (spec §16.1).
 *
 * Sanitising on write, not on render: the storefront prints these fields as
 * HTML, and anything stored dirty is one forgotten `{!! !!}` away from a shop
 * scripting its own customers. Escaping at render would also mean re-deciding
 * the policy at every call site.
 *
 * Whitelist, never blacklist. A blacklist is a list of the attacks someone
 * thought of.
 */
class HtmlSanitizer
{
    /** @var array<string, list<string>> tag => allowed attributes */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [],
        'thead' => [],
        'tbody' => [],
        'tr' => [],
        'th' => ['colspan', 'rowspan'],
        'td' => ['colspan', 'rowspan'],
    ];

    /** Schemes a href or src may use. */
    private const ALLOWED_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    public function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html === null ? null : '';
        }

        $document = new DOMDocument;

        // The wrapper keeps DOMDocument from inventing <html><body>, and the
        // XML declaration is what makes it read the input as UTF-8.
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"?><div id="sanitizer-root">'.$html.'</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('sanitizer-root');

        if ($root === null) {
            return '';
        }

        $this->cleanChildren($root);

        $out = '';

        foreach ($root->childNodes as $child) {
            $out .= $document->saveHTML($child);
        }

        return trim($out);
    }

    private function cleanChildren(DOMNode $node): void
    {
        // Snapshot first: removing while iterating a live DOMNodeList skips
        // siblings, which is how a sanitiser quietly lets nodes through.
        $children = iterator_to_array($node->childNodes);

        foreach ($children as $child) {
            $this->cleanNode($child);
        }
    }

    private function cleanNode(DOMNode $node): void
    {
        if (! $node instanceof DOMElement) {
            // Text and comments: comments go, text stays and is escaped on
            // output by saveHTML.
            if ($node->nodeType === XML_COMMENT_NODE) {
                $node->parentNode?->removeChild($node);
            }

            return;
        }

        $tag = strtolower($node->nodeName);

        if (! array_key_exists($tag, self::ALLOWED)) {
            $this->cleanChildren($node);
            $this->unwrapOrRemove($node, $tag);

            return;
        }

        foreach (iterator_to_array($node->attributes) as $attribute) {
            /** @var DOMAttr $attribute */
            $name = strtolower($attribute->nodeName);

            if (! in_array($name, self::ALLOWED[$tag], true)) {
                $node->removeAttribute($attribute->nodeName);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! $this->isSafeUrl($attribute->nodeValue)) {
                $node->removeAttribute($attribute->nodeName);
            }
        }

        // Links that leave the shop should not hand the opener window over.
        if ($tag === 'a' && $node->getAttribute('target') !== '') {
            $node->setAttribute('rel', 'noopener noreferrer');
        }

        $this->cleanChildren($node);
    }

    /**
     * Drops a disallowed element, keeping its text where that is harmless.
     *
     * A <div> around a paragraph is formatting noise and its content should
     * survive. A <script> body is the payload and must not.
     */
    private function unwrapOrRemove(DOMElement $node, string $tag): void
    {
        $parent = $node->parentNode;

        if ($parent === null) {
            return;
        }

        if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg'], true)) {
            $parent->removeChild($node);

            return;
        }

        while ($node->firstChild !== null) {
            $parent->insertBefore($node->firstChild, $node);
        }

        $parent->removeChild($node);
    }

    private function isSafeUrl(?string $url): bool
    {
        $url = trim((string) $url);

        if ($url === '') {
            return false;
        }

        // Relative and anchor links carry no scheme and are fine.
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if ($scheme === null || $scheme === false) {
            // No scheme and not obviously relative: reject rather than guess.
            // "javascript:alert(1)" parses oddly across PHP versions and this
            // is not a place to be clever.
            return false;
        }

        return in_array(strtolower($scheme), self::ALLOWED_SCHEMES, true);
    }
}
