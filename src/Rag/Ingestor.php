<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Models\RagDocument;
use Awais\RagChat\Support\RagProjectScope;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class Ingestor
{
    public function __construct(
        protected LoaderManager $loaders,
        protected Chunker $chunker,
        protected Embedder $embedder,
        protected VectorStore $store,
    ) {}

    /**
     * Ingest a single file from disk: load -> chunk -> embed -> persist.
     */
    public function ingestFile(string $path, array $meta = []): RagDocument
    {
        $text = $this->loaders->load($path);

        $source = $meta['source'] ?? $path;
        $title = $meta['title'] ?? basename($path);
        unset($meta['source'], $meta['title']);

        return $this->ingestText(
            $text,
            source: $source,
            title: $title,
            meta: $meta,
        );
    }

    /**
     * Ingest a directory recursively, ingesting every supported file.
     *
     * @return RagDocument[]
     */
    public function ingestDirectory(string $directory): array
    {
        if (! is_dir($directory)) {
            throw new RuntimeException("[{$directory}] is not a directory.");
        }

        $documents = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (! $file->isFile()) {
                continue;
            }

            if (! $this->loaders->supports($file->getExtension())) {
                continue;
            }

            $documents[] = $this->ingestFile($file->getPathname());
        }

        return $documents;
    }

    /**
     * Ingest raw text directly (used by the API text endpoint and internally).
     *
     * Documents are deduplicated by a sha256 checksum of their raw content: if
     * an identical document was already ingested, the existing record is
     * returned untouched rather than re-embedding the same text.
     */
    public function ingestText(string $text, string $source, ?string $title = null, array $meta = []): RagDocument
    {
        $pieces = $this->chunker->chunk($text);

        if ($pieces === []) {
            throw new RuntimeException("Document [{$source}] produced no content to ingest.");
        }

        $checksum = hash('sha256', $text);
        $projectId = $meta['project_id'] ?? RagProjectScope::get();
        unset($meta['project_id']);

        $existingQuery = RagDocument::query()->where('checksum', $checksum);

        if ($projectId !== null) {
            $existingQuery->where('project_id', $projectId);
        }

        if ($existing = $existingQuery->first()) {
            return $existing->loadCount('chunks');
        }

        return DB::transaction(function () use ($pieces, $source, $title, $meta, $checksum, $projectId) {
            $document = RagDocument::create([
                'project_id' => $projectId,
                'source' => $source,
                'title' => $title ?? $source,
                'checksum' => $checksum,
                'meta' => $meta ?: null,
            ]);

            $dimensions = $this->embedder->dimensions();
            $batchSize = max(1, (int) config('rag-chat.embedding.batch', 100));

            $position = 0;

            foreach (array_chunk($pieces, $batchSize) as $batch) {
                $vectors = $this->embedder->embedDocuments($batch);

                $chunks = [];

                foreach ($batch as $i => $content) {
                    $chunks[] = [
                        'position' => $position++,
                        'content' => $content,
                        'vector' => $vectors[$i],
                        'dimensions' => $dimensions ?? count($vectors[$i]),
                        'meta' => null,
                    ];
                }

                $this->store->insert($document->id, $chunks);
            }

            return $document->refresh()->loadCount('chunks');
        });
    }
}
