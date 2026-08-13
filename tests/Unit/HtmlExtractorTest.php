<?php

namespace Awais\RagChat\Tests\Unit;

use Awais\RagChat\Crawl\HtmlExtractor;
use Awais\RagChat\Tests\TestCase;

class HtmlExtractorTest extends TestCase
{
    public function test_extracts_plain_text_from_html(): void
    {
        $extractor = new HtmlExtractor;

        $result = $extractor->extract('<html><head><title>About</title></head><body><h1>Hello</h1><p>World</p></body></html>');

        $this->assertSame('About', $result['title']);
        $this->assertStringContainsString('Hello', $result['text']);
        $this->assertStringContainsString('World', $result['text']);
    }

    public function test_decodes_cloudflare_obfuscated_emails(): void
    {
        $extractor = new HtmlExtractor;

        // data-cfemail for "test@example.com" XOR-encoded against key 0x5a.
        $html = '<html><body><p>Email Us</p><a class="__cf_email__" data-cfemail="5a2e3f292e1a3f223b372a363f74393537">[email&#160;protected]</a></body></html>';

        $result = $extractor->extract($html);

        $this->assertStringContainsString('test@example.com', $result['text']);
        $this->assertStringNotContainsString('[email', $result['text']);
    }

    public function test_leaves_plain_emails_untouched(): void
    {
        $extractor = new HtmlExtractor;

        $result = $extractor->extract('<html><body><p>Reach me at hello@example.com</p></body></html>');

        $this->assertStringContainsString('hello@example.com', $result['text']);
    }

    public function test_ignores_invalid_cfemail_values(): void
    {
        $extractor = new HtmlExtractor;

        $html = '<html><body><a data-cfemail="zz">[email&#160;protected]</a></body></html>';

        $result = $extractor->extract($html);

        $this->assertStringContainsString('[email', $result['text']);
    }
}
