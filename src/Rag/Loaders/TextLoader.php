<?php

namespace Awais\RagChat\Rag\Loaders;

use Awais\RagChat\Contracts\DocumentLoader;
use RuntimeException;

class TextLoader implements DocumentLoader
{
    public function extensions(): array
    {
        return ['txt'];
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

        return $this->normalize($contents);
    }

    /**
     * Normalize line endings and collapse excessive blank lines.
     */
    protected function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }
}
