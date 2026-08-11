<?php

namespace Awais\RagChat\Rag\Loaders;

use Awais\RagChat\Contracts\DocumentLoader;
use RuntimeException;
use Smalot\PdfParser\Parser;

class PdfLoader implements DocumentLoader
{
    public function __construct(
        protected ?Parser $parser = null,
    ) {
        $this->parser ??= new Parser();
    }

    public function extensions(): array
    {
        return ['pdf'];
    }

    public function load(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read document at [{$path}].");
        }

        try {
            $pdf = $this->parser->parseFile($path);
            $text = $pdf->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Failed to parse PDF at [{$path}]: {$e->getMessage()}",
                previous: $e,
            );
        }

        $text = $this->normalize($text);

        if ($text === '') {
            throw new RuntimeException(
                "PDF [{$path}] produced no extractable text. Scanned/image-only PDFs are not supported without OCR."
            );
        }

        return $text;
    }

    /**
     * Normalize line endings and collapse excessive whitespace for embedding.
     */
    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        // PDF extractors often inject form-feed / null bytes.
        $text = str_replace(["\x0C", "\0"], "\n", $text);
        $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
