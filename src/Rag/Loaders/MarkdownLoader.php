<?php

namespace Awais\RagChat\Rag\Loaders;

use Awais\RagChat\Contracts\DocumentLoader;
use RuntimeException;

class MarkdownLoader implements DocumentLoader
{
    public function extensions(): array
    {
        return ['md', 'markdown'];
    }

    public function load(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("Cannot read document at [{$path}].");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Failed to read document at [{$path}].");
        }

        return $this->toPlainText($contents);
    }

    /**
     * Strip the noisiest Markdown syntax while preserving readable text.
     * We keep it lightweight — the goal is clean text for embedding, not
     * a full CommonMark render.
     */
    protected function toPlainText(string $md): string
    {
        $md = str_replace(["\r\n", "\r"], "\n", $md);

        // Remove fenced code block markers but keep the code text.
        $md = preg_replace('/^```[a-zA-Z0-9]*\n/m', '', $md);
        $md = str_replace('```', '', $md);

        // Images: ![alt](url) -> alt
        $md = preg_replace('/!\[([^\]]*)\]\([^)]*\)/', '$1', $md);
        // Links: [text](url) -> text
        $md = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $md);
        // Headings, blockquotes, list bullets at line start.
        $md = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $md);
        $md = preg_replace('/^\s{0,3}>\s?/m', '', $md);
        $md = preg_replace('/^\s{0,3}[-*+]\s+/m', '', $md);
        // Emphasis markers.
        $md = preg_replace('/(\*\*|__|\*|_|`)/', '', $md);

        $md = preg_replace("/\n{3,}/", "\n\n", $md);

        return trim($md);
    }
}
