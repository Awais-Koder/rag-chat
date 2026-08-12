<?php

namespace Awais\RagChat\Citations;

use Awais\RagChat\Models\RagChunk;
use Awais\RagChat\Models\RagDocument;

/**
 * A single retrieval match enriched with a stable, prompt-local source ID.
 *
 * The source ID is the only handle the LLM may use when citing. It maps back
 * to the actual chunk + document metadata without ever exposing server paths
 * or internal storage details.
 */
final class RetrievedChunk
{
    public function __construct(
        public readonly int $sourceId,
        public readonly RagChunk $chunk,
        public readonly float $score,
    ) {
    }

    public function document(): ?RagDocument
    {
        return $this->chunk->document;
    }

    public function documentId(): int
    {
        return (int) $this->chunk->document_id;
    }

    public function chunkId(): int
    {
        return (int) $this->chunk->id;
    }

    /**
     * Public, safe display name for the source document.
     */
    public function documentName(): string
    {
        $document = $this->chunk->document;

        if ($document && filled($document->title)) {
            return (string) $document->title;
        }

        if ($document && filled($document->source)) {
            return basename((string) $document->source);
        }

        return "Document #{$this->documentId()}";
    }

    public function documentType(): ?string
    {
        return $this->metaValue('document_type');
    }

    public function page(): ?int
    {
        $page = $this->metaValue('page');

        return is_numeric($page) ? (int) $page : null;
    }

    public function section(): ?string
    {
        return $this->metaValue('section');
    }

    public function heading(): ?string
    {
        return $this->metaValue('heading');
    }

    public function sourceUrl(): ?string
    {
        return $this->metaValue('source_url');
    }

    /**
     * Ordinal of the chunk within its document (falls back to the position
     * column when no explicit chunk_index meta exists).
     */
    public function chunkIndex(): int
    {
        $index = $this->metaValue('chunk_index');

        return is_numeric($index) ? (int) $index : (int) $this->chunk->position;
    }

    /**
     * Parent chunk id when this chunk was ingested with parent-child chunking.
     */
    public function parentChunkId(): ?int
    {
        $parentId = $this->metaValue('parent_chunk_id');

        return is_numeric($parentId) ? (int) $parentId : null;
    }

    /**
     * Optional contextual prefix attached at ingest (contextual chunking).
     */
    public function context(): ?string
    {
        return $this->metaValue('context');
    }

    /**
     * Read a metadata key from the chunk meta first, then the document meta.
     */
    protected function metaValue(string $key): mixed
    {
        $chunkMeta = is_array($this->chunk->meta) ? $this->chunk->meta : [];
        $document = $this->chunk->document;
        $documentMeta = is_array($document?->meta) ? $document->meta : [];

        if (array_key_exists($key, $chunkMeta) && filled($chunkMeta[$key])) {
            return $chunkMeta[$key];
        }

        if (array_key_exists($key, $documentMeta) && filled($documentMeta[$key])) {
            return $documentMeta[$key];
        }

        return null;
    }
}
