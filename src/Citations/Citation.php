<?php

namespace Awais\RagChat\Citations;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * A validated, public-safe citation pointing at a real retrieved chunk.
 *
 * Only explicitly safe fields are exposed: never file paths, checksums,
 * embeddings, or internal storage details.
 */
final class Citation implements Arrayable, Jsonable, JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly int $documentId,
        public readonly string $documentName,
        public readonly ?string $documentType,
        public readonly int $chunkId,
        public readonly ?int $page,
        public readonly ?string $section,
        public readonly ?string $sourceUrl,
        public readonly float $score,
    ) {
    }

    public static function fromRetrievedChunk(RetrievedChunk $chunk): self
    {
        return new self(
            id: $chunk->sourceId,
            documentId: $chunk->documentId(),
            documentName: $chunk->documentName(),
            documentType: $chunk->documentType(),
            chunkId: $chunk->chunkId(),
            page: $chunk->page(),
            section: $chunk->section(),
            sourceUrl: $chunk->sourceUrl(),
            score: round($chunk->score, 4),
        );
    }

    /**
     * Rebuild a citation from its toArray() representation (cache hydration).
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) ($data['id'] ?? 0),
            documentId: (int) ($data['document_id'] ?? 0),
            documentName: (string) ($data['document_name'] ?? ''),
            documentType: isset($data['document_type']) ? (string) $data['document_type'] : null,
            chunkId: (int) ($data['chunk_id'] ?? 0),
            page: isset($data['page']) ? (int) $data['page'] : null,
            section: isset($data['section']) ? (string) $data['section'] : null,
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
            score: (float) ($data['score'] ?? 0.0),
        );
    }

    /**
     * @return array{id: int, document_id: int, document_name: string, document_type: string|null, chunk_id: int, page: int|null, section: string|null, source_url: string|null, score: float}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->documentId,
            'document_name' => $this->documentName,
            'document_type' => $this->documentType,
            'chunk_id' => $this->chunkId,
            'page' => $this->page,
            'section' => $this->section,
            'source_url' => $this->sourceUrl,
            'score' => $this->score,
        ];
    }

    public function toJson($options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
