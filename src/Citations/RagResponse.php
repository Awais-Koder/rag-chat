<?php

namespace Awais\RagChat\Citations;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;

/**
 * The result of a citation-aware chat turn: the plain answer text plus the
 * validated citations that back it.
 */
final class RagResponse implements Arrayable, Jsonable, JsonSerializable
{
    /**
     * @param  array<string, mixed>  $metadata  run-level metadata (rag_run_id, usage, latency)
     */
    public function __construct(
        public readonly string $answer,
        public readonly CitationCollection $citations,
        public readonly array $sources = [],
        public readonly array $metadata = [],
    ) {
    }

    public function hasCitations(): bool
    {
        return ! $this->citations->isEmpty();
    }

    /**
     * @return array{answer: string, citations: list<array<string, mixed>>, sources: array<int, array<string, mixed>>, metadata: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'answer' => $this->answer,
            'citations' => $this->citations->toArray(),
            'sources' => $this->sources,
            'metadata' => $this->metadata,
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
