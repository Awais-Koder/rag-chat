<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Contracts\VectorStore;
use Awais\RagChat\Models\RagChunk;
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
        $source = $meta['source'] ?? $path;
        $title = $meta['title'] ?? basename($path);
        unset($meta['source'], $meta['title']);

        // Page-aware loaders (PDF) let us tag each chunk with its page number
        // so citations can point at a specific page.
        $pages = $this->loaders->loadPages($path);

        if ($pages !== null) {
            return $this->ingestPages(
                $pages,
                source: $source,
                title: $title,
                meta: $meta,
            );
        }

        $text = $this->loaders->load($path);

        return $this->ingestText(
            $text,
            source: $source,
            title: $title,
            meta: $meta,
        );
    }

    /**
     * Ingest page-numbered text, recording the page number on every chunk.
     *
     * @param  array<int, string>  $pages  keyed by 1-based page number
     */
    public function ingestPages(array $pages, string $source, ?string $title = null, array $meta = []): RagDocument
    {
        $pieces = [];
        $piecePages = [];

        foreach ($pages as $pageNumber => $text) {
            foreach ($this->chunker->chunk($text) as $piece) {
                $pieces[] = $piece;
                $piecePages[] = $pageNumber;
            }
        }

        if ($pieces === []) {
            throw new RuntimeException("Document [{$source}] produced no content to ingest.");
        }

        $checksum = hash('sha256', implode("\n\n", array_values($pages)));
        $projectId = $meta['project_id'] ?? RagProjectScope::get();
        unset($meta['project_id']);

        [$existing, $versionMeta] = $this->resolveDuplicate($checksum, $projectId);

        if ($existing !== null && $versionMeta === null) {
            return $existing->loadCount('chunks');
        }

        [$reuseVectors, $previousCount] = $this->previousSourceChunks($source, $projectId);

        return $this->createDocumentAndStore(
            pieces: $pieces,
            piecePages: $piecePages,
            source: $source,
            title: $title,
            meta: $meta,
            checksum: $checksum,
            projectId: $projectId,
            versionMeta: $versionMeta,
            reuseVectors: $reuseVectors,
            previousCount: $previousCount,
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
     * returned untouched rather than re-embedding the same text — unless
     * indexing.duplicates is 'create_new_version', which instead bumps the
     * version (stored in meta) and indexes a new row.
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

        [$existing, $versionMeta] = $this->resolveDuplicate($checksum, $projectId);

        if ($existing !== null && $versionMeta === null) {
            return $existing->loadCount('chunks');
        }

        [$reuseVectors, $previousCount] = $this->previousSourceChunks($source, $projectId);

        return $this->createDocumentAndStore(
            pieces: $pieces,
            piecePages: [],
            source: $source,
            title: $title,
            meta: $meta,
            checksum: $checksum,
            projectId: $projectId,
            versionMeta: $versionMeta,
            reuseVectors: $reuseVectors,
            previousCount: $previousCount,
        );
    }

    /**
     * Run the queued-indexing path for a pending document row created by
     * RagChat (indexing.queue). Loads the stored source, chunks + embeds into
     * the SAME document row, and marks it indexed (or failed on error).
     */
    public function processPending(RagDocument $document): RagDocument
    {
        $this->markStatus($document, 'processing');

        try {
            $meta = $document->meta ?? [];
            $pieces = [];
            $piecePages = [];
            $checksum = null;

            if (isset($meta['pending_text'])) {
                $text = (string) $meta['pending_text'];
                $pieces = $this->chunker->chunk($text);
                $checksum = hash('sha256', $text);
            } else {
                $path = (string) ($meta['pending_path'] ?? $document->source);
                $pages = is_string($path) ? $this->loaders->loadPages($path) : null;

                if ($pages !== null) {
                    foreach ($pages as $pageNumber => $pageText) {
                        foreach ($this->chunker->chunk($pageText) as $piece) {
                            $pieces[] = $piece;
                            $piecePages[] = $pageNumber;
                        }
                    }

                    $checksum = hash('sha256', implode("\n\n", array_values($pages)));
                } else {
                    $text = $this->loaders->load($path);
                    $pieces = $this->chunker->chunk($text);
                    $checksum = hash('sha256', $text);
                }
            }

            if ($pieces === []) {
                throw new RuntimeException("Document [{$document->source}] produced no content to ingest.");
            }

            $projectId = $document->project_id ?? RagProjectScope::get();

            // Identical content already exists as a non-pending document?
            $existing = RagDocument::query()
                ->where('checksum', $checksum)
                ->when($projectId !== null, fn ($query) => $query->where('project_id', $projectId))
                ->whereKeyNot($document->id)
                ->first();

            if ($existing && config('rag-chat.indexing.duplicates', 'reject') !== 'create_new_version') {
                $document->update([
                    'meta' => array_merge($document->meta ?? [], ['status' => 'duplicate', 'duplicate_of' => $existing->id]),
                ]);

                return $existing->loadCount('chunks');
            }

            [$reuseVectors, $previousCount] = $this->previousSourceChunks((string) $document->source, $projectId);

            return DB::transaction(function () use ($document, $pieces, $piecePages, $checksum, $reuseVectors, $previousCount) {
                $documentMeta = array_merge($document->meta ?? [], [
                    'status' => 'indexed',
                    'indexed_at' => now()->toISOString(),
                ]);
                unset($documentMeta['pending_text'], $documentMeta['pending_path']);

                $document->update([
                    'checksum' => $checksum,
                    'meta' => $documentMeta,
                ]);

                $report = $this->storeChunks($document, $pieces, $piecePages, $reuseVectors, $previousCount);

                $document->update([
                    'meta' => array_merge($documentMeta, [
                        'ingest_report' => $report->toArray(),
                        'chunk_count' => $report->added + $report->unchanged,
                    ]),
                ]);

                return $document->refresh()->loadCount('chunks');
            });
        } catch (\Throwable $exception) {
            $this->markStatus($document, 'failed', $exception->getMessage());

            throw $exception;
        }
    }

    /**
     * Create a document row (with version/status metadata) and store its
     * chunks, returning the fresh document.
     *
     * @param  array<int, string>  $pieces
     * @param  array<int, int>  $piecePages
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>|null  $versionMeta
     * @param  array<string, array<float>>  $reuseVectors
     */
    protected function createDocumentAndStore(
        array $pieces,
        array $piecePages,
        string $source,
        ?string $title,
        array $meta,
        string $checksum,
        ?int $projectId,
        ?array $versionMeta,
        array $reuseVectors,
        int $previousCount,
    ): RagDocument {
        return DB::transaction(function () use ($pieces, $piecePages, $source, $title, $meta, $checksum, $projectId, $versionMeta, $reuseVectors, $previousCount) {
            $documentMeta = $meta ?: [];

            if ($versionMeta !== null) {
                $documentMeta = array_merge($documentMeta, $versionMeta);
            }

            $documentMeta['status'] = 'indexed';
            $documentMeta['indexed_at'] = now()->toISOString();

            $document = RagDocument::create([
                'project_id' => $projectId,
                'source' => $source,
                'title' => $title ?? $source,
                'checksum' => $checksum,
                'meta' => $documentMeta ?: null,
            ]);

            $report = $this->storeChunks($document, $pieces, $piecePages, $reuseVectors, $previousCount);

            $document->update([
                'meta' => array_merge($documentMeta, [
                    'ingest_report' => $report->toArray(),
                    'chunk_count' => $report->added + $report->unchanged,
                ]),
            ]);

            return $document->refresh()->loadCount('chunks');
        });
    }

    /**
     * Chunk -> embed -> store pipeline shared by every ingest path.
     *
     * Embeds only the chunks whose content hash is new; unchanged chunks reuse
     * the previous same-source document's embedding (incremental indexing).
     *
     * @param  array<int, string>  $pieces
     * @param  array<int, int>  $piecePages
     * @param  array<string, array<float>>  $reuseVectors  content_hash => embedding
     */
    protected function storeChunks(
        RagDocument $document,
        array $pieces,
        array $piecePages,
        array $reuseVectors = [],
        int $previousCount = 0,
    ): IndexReport {
        $dimensions = $this->embedder->dimensions();
        $batchSize = max(1, (int) config('rag-chat.embedding.batch', 100));
        $contextPrefix = $this->contextPrefix($document->title ?? (string) $document->source);
        $incremental = (bool) config('rag-chat.indexing.incremental', false);

        $hashes = [];
        $vectors = [];
        $reused = 0;
        $toEmbed = [];

        foreach ($pieces as $i => $content) {
            $hash = $incremental ? hash('sha256', $content) : null;
            $hashes[$i] = $hash;

            if ($hash !== null && isset($reuseVectors[$hash])) {
                $vectors[$i] = $reuseVectors[$hash];
                $reused++;
            } else {
                $toEmbed[] = ['index' => $i, 'content' => $content];
            }
        }

        foreach (array_chunk($toEmbed, $batchSize) as $batch) {
            $batchVectors = $this->embedder->embedDocuments(
                array_map(fn (array $entry) => $entry['content'], $batch)
            );

            foreach ($batch as $j => $entry) {
                $vectors[$entry['index']] = $batchVectors[$j];
            }
        }

        $chunks = [];
        $position = 0;

        foreach ($pieces as $i => $content) {
            $chunkMeta = [];

            if ($hashes[$i] !== null) {
                $chunkMeta['content_hash'] = $hashes[$i];
            }

            if (isset($piecePages[$i])) {
                $chunkMeta['page'] = $piecePages[$i];
            }

            if ($contextPrefix !== null) {
                $chunkMeta['context'] = $contextPrefix;
            }

            $chunks[] = [
                'position' => $position++,
                'content' => $content,
                'vector' => $vectors[$i],
                'dimensions' => $dimensions ?? count($vectors[$i]),
                'meta' => $chunkMeta !== [] ? $chunkMeta : null,
            ];
        }

        $this->store->insert($document->id, $chunks);

        $this->maybeCreateParents($document, count($pieces));

        return new IndexReport(
            added: count($pieces) - $reused,
            updated: 0,
            removed: max(0, $previousCount - $reused),
            unchanged: $reused,
            reusedEmbeddings: $reused,
        );
    }

    /**
     * Find an existing document with identical content, honoring the
     * configured duplicate behavior.
     *
     * @return array{0: RagDocument|null, 1: array<string, mixed>|null}  [existing, versionMeta]
     */
    protected function resolveDuplicate(string $checksum, ?int $projectId): array
    {
        $existingQuery = RagDocument::query()->where('checksum', $checksum);

        if ($projectId !== null) {
            $existingQuery->where('project_id', $projectId);
        }

        $existing = $existingQuery->first();

        if ($existing === null) {
            return [null, null];
        }

        if (config('rag-chat.indexing.duplicates', 'reject') === 'create_new_version') {
            // Backfill the original row with an explicit version 1 so the
            // lineage (version / version_of / previous_id) is unambiguous.
            if (! isset($existing->meta['version'])) {
                $existing->update([
                    'meta' => array_merge($existing->meta ?? [], [
                        'version' => 1,
                        'version_of' => (int) $existing->id,
                    ]),
                ]);
            }

            return [
                $existing,
                [
                    'version' => ((int) ($existing->meta['version'] ?? 1)) + 1,
                    'version_of' => (int) ($existing->meta['version_of'] ?? $existing->id),
                    'previous_id' => (int) $existing->id,
                ],
            ];
        }

        return [$existing, null];
    }

    /**
     * Embeddings of the most recent same-source document keyed by chunk
     * content hash, for incremental reuse. Returns [vectors, previousCount].
     *
     * @return array{0: array<string, array<float>>, 1: int}
     */
    protected function previousSourceChunks(string $source, ?int $projectId): array
    {
        if (! (bool) config('rag-chat.indexing.incremental', true)) {
            return [[], 0];
        }

        $query = RagDocument::query()->where('source', $source);

        if ($projectId !== null) {
            $query->where('project_id', $projectId);
        }

        $previous = $query->orderByDesc('id')->first();

        if ($previous === null) {
            return [[], 0];
        }

        $reuse = [];

        foreach ($previous->chunks()->get() as $chunk) {
            $hash = $chunk->meta['content_hash'] ?? null;

            if ($hash !== null) {
                $reuse[$hash] = $chunk->embedding;
            }
        }

        return [$reuse, count($reuse)];
    }

    /**
     * Optional contextual-chunking prefix stored on every chunk's meta
     * (features.contextual_chunking). Kept separate from content so the
     * original text and embeddings stay untouched — the context builder can
     * decide whether to render it.
     */
    protected function contextPrefix(string $title): ?string
    {
        if (! (bool) config('rag-chat.features.contextual_chunking', false)) {
            return null;
        }

        return 'Document: '.$title;
    }

    /**
     * Optionally create parent chunks that group consecutive child chunks, so
     * context expansion can widen a retrieved child with its parent section.
     *
     * Relationships live entirely in the flexible meta JSON — no schema
     * change, and plain Tier 1 ingestion is unaffected when the feature is
     * off: parents carry is_parent + child_ids, children carry parent_chunk_id.
     */
    protected function maybeCreateParents(RagDocument $document, int $childCount): void
    {
        if (! (bool) config('rag-chat.features.parent_child', false) || $childCount === 0) {
            return;
        }

        $window = max(1, (int) config('rag-chat.ingestion.parent_window', 4));

        $children = RagChunk::query()
            ->where('document_id', $document->id)
            ->orderBy('position')
            ->limit($childCount)
            ->get();

        if ($children->isEmpty()) {
            return;
        }

        $groups = $children->chunk($window)->values();
        $parentPieces = $groups->map(fn ($group) => $group->pluck('content')->implode("\n\n"))->all();
        $vectors = $this->embedder->embedDocuments($parentPieces);
        $dimensions = $this->embedder->dimensions();

        $contextPrefix = $this->contextPrefix($document->title ?? (string) $document->source);

        $rows = [];
        $position = $childCount;

        foreach ($groups as $i => $group) {
            $parentMeta = [
                'is_parent' => true,
                'child_ids' => $group->pluck('id')->map(fn ($id) => (int) $id)->all(),
            ];

            $firstPage = $group->first()?->meta['page'] ?? null;

            if ($firstPage !== null) {
                $parentMeta['page'] = (int) $firstPage;
            }

            if ($contextPrefix !== null) {
                $parentMeta['context'] = $contextPrefix;
            }

            $rows[] = [
                'position' => $position++,
                'content' => $parentPieces[$i],
                'vector' => $vectors[$i],
                'dimensions' => $dimensions ?? count($vectors[$i]),
                'meta' => $parentMeta,
            ];
        }

        $this->store->insert($document->id, $rows);

        // Link children back to their parent in a single upsert (preserving
        // each child's own page/context meta) instead of one UPDATE per child.
        $parentIds = RagChunk::query()
            ->where('document_id', $document->id)
            ->where('position', '>=', $childCount)
            ->orderBy('position')
            ->pluck('id')
            ->values();

        $upserts = [];

        foreach ($groups as $i => $group) {
            foreach ($group as $child) {
                $childMeta = array_merge($child->meta ?? [], ['parent_chunk_id' => (int) $parentIds[$i]]);

                // Full rows: the upsert INSERT branch needs every NOT NULL
                // column; only `meta` is touched on conflict.
                $upserts[] = [
                    'id' => $child->id,
                    'document_id' => $child->document_id,
                    'position' => $child->position,
                    'content' => $child->content,
                    'embedding' => json_encode($child->embedding),
                    'dimensions' => $child->dimensions,
                    'meta' => json_encode($childMeta),
                ];
            }
        }

        RagChunk::query()->upsert($upserts, ['id'], ['meta']);
    }

    /**
     * Set (or merge) the indexing status on a document's meta.
     */
    protected function markStatus(RagDocument $document, string $status, ?string $error = null): void
    {
        $meta = array_merge($document->meta ?? [], ['status' => $status]);

        if ($error !== null) {
            $meta['error'] = $error;
        }

        $document->update(['meta' => $meta]);
    }
}
