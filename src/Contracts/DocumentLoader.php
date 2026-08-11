<?php

namespace Awais\RagChat\Contracts;

interface DocumentLoader
{
    /**
     * The file extensions (without dot) this loader can handle.
     *
     * @return string[]
     */
    public function extensions(): array;

    /**
     * Extract plain text from the given file path.
     */
    public function load(string $path): string;
}
