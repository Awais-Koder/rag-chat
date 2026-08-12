<?php

namespace Awais\RagChat\Citations;

use Awais\RagChat\Models\RagChunk;

/**
 * Request-scoped map between real chunks and the stable source IDs shown to
 * the LLM. Both the prompt context builder and the SearchKnowledge tool write
 * to the same registry, so a citation ID always resolves to a real chunk no
 * matter which path produced it.
 */
class CitationRegistry
{
    /**
     * @var array<int, RetrievedChunk> keyed by chunk id
     */
    protected array $byChunkId = [];

    /**
     * @var array<int, RetrievedChunk> keyed by source id
     */
    protected array $bySourceId = [];

    protected int $nextSourceId = 1;

    /**
     * Forget all registered chunks. Call once per chat turn.
     */
    public function reset(): void
    {
        $this->byChunkId = [];
        $this->bySourceId = [];
        $this->nextSourceId = 1;
    }

    /**
     * Register a chunk, returning its (possibly existing) source ID.
     */
    public function register(RagChunk $chunk, float $score): int
    {
        $chunkId = (int) $chunk->id;

        if (isset($this->byChunkId[$chunkId])) {
            return $this->byChunkId[$chunkId]->sourceId;
        }

        $sourceId = $this->nextSourceId++;
        $retrieved = new RetrievedChunk($sourceId, $chunk, $score);

        $this->byChunkId[$chunkId] = $retrieved;
        $this->bySourceId[$sourceId] = $retrieved;

        return $sourceId;
    }

    public function sourceIdFor(int $chunkId): ?int
    {
        return isset($this->byChunkId[$chunkId])
            ? $this->byChunkId[$chunkId]->sourceId
            : null;
    }

    public function retrieved(int $sourceId): ?RetrievedChunk
    {
        return $this->bySourceId[$sourceId] ?? null;
    }

    /**
     * @return array<int, RetrievedChunk> keyed by source id, ascending
     */
    public function all(): array
    {
        ksort($this->bySourceId);

        return $this->bySourceId;
    }

    public function count(): int
    {
        return count($this->bySourceId);
    }
}
