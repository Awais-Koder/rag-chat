<?php

namespace Awais\RagChat\Crawl;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;

/**
 * Strips markup from a crawled page and returns a plain-text excerpt plus the
 * <title>. Uses PHP's built-in DOM extension — no extra dependencies.
 *
 * Boilerplate (scripts, styles, navigation, footers) is removed, and block
 * elements / headings are turned into line breaks so the extracted text keeps
 * readable structure for the chunker and the LLM.
 */
class HtmlExtractor
{
    /**
     * Tags whose contents are never useful for a knowledge base.
     *
     * @var string[]
     */
    protected const NOISE_TAGS = [
        'script', 'style', 'noscript', 'template', 'iframe', 'svg', 'canvas',
        'nav', 'footer', 'aside', 'form', 'button', 'select', 'option',
    ];

    /**
     * Elements that should be followed by a line break in the extracted text.
     *
     * @var string[]
     */
    protected const BLOCK_TAGS = [
        'p', 'div', 'section', 'article', 'li', 'ul', 'ol', 'blockquote',
        'tr', 'table', 'br', 'hr', 'pre', 'header', 'main',
    ];

    /**
     * @return array{title: string|null, text: string}
     */
    public function extract(string $html): array
    {
        $document = $this->load($html);

        // Sites behind Cloudflare serve emails obfuscated as "[email protected]"
        // with the real address XOR-encoded in data-cfemail. Decode them so the
        // knowledge base (and citations) contain the actual address.
        $this->deobfuscateEmails($document);

        $titleNode = $document->getElementsByTagName('title')->item(0);
        $title = $titleNode !== null ? trim((string) $titleNode->textContent) : null;

        $this->removeNoise($document);

        $body = $document->getElementsByTagName('body')->item(0)
            ?? $document->documentElement;

        $text = $this->collectText($body);

        return [
            'title' => filled($title) ? $title : null,
            'text' => $this->normalize($text),
        ];
    }

    /**
     * Load HTML into a DOMDocument, tolerating broken markup.
     */
    protected function load(string $html): DOMDocument
    {
        $document = new DOMDocument;

        // The leading XML declaration forces UTF-8 handling without relying
        // on mbstring being installed.
        $html = '<?xml encoding="UTF-8">'.$html;

        libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        return $document;
    }

    /**
     * Remove boilerplate subtrees entirely.
     */
    protected function removeNoise(DOMDocument $document): void
    {
        foreach (static::NOISE_TAGS as $tag) {
            foreach (iterator_to_array($document->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }
    }

    /**
     * Recursively walk the DOM, emitting block boundaries as newlines and
     * prefixing headings so the chunker sees document structure.
     */
    protected function collectText(DOMNode $node): string
    {
        $text = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText) {
                $text .= $child->nodeValue;

                continue;
            }

            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (preg_match('/^h[1-6]$/', $tag) === 1) {
                $text .= "\n".trim($child->textContent)."\n";
            } else {
                $text .= $this->collectText($child);
            }

            if (in_array($tag, static::BLOCK_TAGS, true)) {
                $text .= "\n";
            }
        }

        return $text;
    }

    /**
     * Restore Cloudflare-obfuscated email addresses.
     *
     * Cloudflare replaces email addresses in served HTML with the literal text
     * "[email protected]" and stores the real address hex-encoded in a
     * data-cfemail attribute, XORed against its first byte. When that attribute
     * is present, the visible placeholder is swapped for the decoded address so
     * crawls capture the actual contact email.
     */
    protected function deobfuscateEmails(DOMDocument $document): void
    {
        $xpath = new DOMXPath($document);

        foreach ($xpath->query('//*[@data-cfemail]') as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $email = $this->decodeCloudflareEmail((string) $node->getAttribute('data-cfemail'));

            if ($email === '') {
                continue;
            }

            $node->textContent = $email;
            $node->removeAttribute('data-cfemail');
        }
    }

    /**
     * Decode Cloudflare's XOR-encoded data-cfemail value.
     */
    protected function decodeCloudflareEmail(string $encoded): string
    {
        $bytes = array_map('hexdec', str_split($encoded, 2));

        if (count($bytes) < 2) {
            return '';
        }

        $key = array_shift($bytes);
        $decoded = '';

        foreach ($bytes as $byte) {
            $decoded .= chr($byte ^ $key);
        }

        return $decoded;
    }

    /**
     * Collapse runs of whitespace and blank lines into clean paragraphs.
     */
    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
