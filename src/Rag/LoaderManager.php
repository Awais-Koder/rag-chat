<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Contracts\DocumentLoader;
use Awais\RagChat\Rag\Loaders\MarkdownLoader;
use Awais\RagChat\Rag\Loaders\PdfLoader;
use Awais\RagChat\Rag\Loaders\TextLoader;
use RuntimeException;

class LoaderManager
{
    /**
     * @var DocumentLoader[]
     */
    protected array $loaders = [];

    public function __construct()
    {
        $this->register(new TextLoader);
        $this->register(new MarkdownLoader);
        $this->register(new PdfLoader);
    }

    public function register(DocumentLoader $loader): void
    {
        $this->loaders[] = $loader;
    }

    /**
     * Extensions supported across all registered loaders.
     *
     * @return string[]
     */
    public function supportedExtensions(): array
    {
        $extensions = [];

        foreach ($this->loaders as $loader) {
            $extensions = array_merge($extensions, $loader->extensions());
        }

        return array_values(array_unique($extensions));
    }

    public function supports(string $extension): bool
    {
        return in_array(strtolower($extension), $this->supportedExtensions(), true);
    }

    /**
     * Load and extract text from a file, choosing a loader by extension.
     */
    public function load(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach ($this->loaders as $loader) {
            if (in_array($extension, array_map('strtolower', $loader->extensions()), true)) {
                return $loader->load($path);
            }
        }

        throw new RuntimeException(
            "No loader registered for [.{$extension}] files. Supported: "
            .implode(', ', $this->supportedExtensions()).'.'
        );
    }

    /**
     * Load a file as page-numbered text when its loader supports pages.
     *
     * @return array<int, string>|null keyed by 1-based page number, or null when unsupported
     */
    public function loadPages(string $path): ?array
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        foreach ($this->loaders as $loader) {
            if (! in_array($extension, array_map('strtolower', $loader->extensions()), true)) {
                continue;
            }

            if (method_exists($loader, 'loadPages')) {
                return $loader->loadPages($path);
            }

            return null;
        }

        throw new RuntimeException(
            "No loader registered for [.{$extension}] files. Supported: "
            .implode(', ', $this->supportedExtensions()).'.'
        );
    }
}
