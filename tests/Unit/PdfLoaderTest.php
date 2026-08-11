<?php

namespace Awais\RagChat\Tests\Unit;

use Awais\RagChat\Rag\Loaders\PdfLoader;
use Awais\RagChat\Tests\TestCase;
use RuntimeException;

class PdfLoaderTest extends TestCase
{
    public function test_it_extracts_text_from_a_pdf(): void
    {
        $loader = new PdfLoader();
        $path = __DIR__.'/../fixtures/acme/pricing.pdf';

        $this->assertSame(['pdf'], $loader->extensions());

        $text = $loader->load($path);

        $this->assertStringContainsString('Acme Robotics PDF pricing notes', $text);
        $this->assertStringContainsString('45000', $text);
    }

    public function test_it_rejects_missing_files(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot read document');

        (new PdfLoader())->load(__DIR__.'/../fixtures/acme/does-not-exist.pdf');
    }
}
