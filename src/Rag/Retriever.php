<?php

namespace Awais\RagChat\Rag;

use Awais\RagChat\Contracts\VectorStore;
use Illuminate\Support\Collection;

class Retriever
{
    public function __construct(
        protected Embedder $embedder,
        protected VectorStore $store,
    ) {}

    /**
     * Retrieve the top-K most similar chunks for a query.
     *
     * When query expansion is enabled, runs supplemental keyword-oriented searches
     * for intent-style questions and merges the best score per chunk.
     *
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    public function retrieve(string $query): Collection
    {
        $topK = (int) config('rag-chat.retrieval.top_k', 5);
        $minScore = (float) config('rag-chat.retrieval.min_score', 0.0);
        $expand = (bool) config('rag-chat.retrieval.expand_queries', true);

        $searchQueries = $expand
            ? QueryExpander::queries($query)
            : [$query];

        /** @var array<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}> $bestByChunkId */
        $bestByChunkId = [];

        foreach ($searchQueries as $searchQuery) {
            foreach ($this->searchOnce($searchQuery, $topK, $minScore) as $match) {
                $chunkId = (int) $match['chunk']->id;

                if (
                    ! isset($bestByChunkId[$chunkId])
                    || $match['score'] > $bestByChunkId[$chunkId]['score']
                ) {
                    $bestByChunkId[$chunkId] = $match;
                }
            }
        }

        return collect($bestByChunkId)
            ->sortByDesc('score')
            ->take($topK)
            ->values();
    }

    /**
     * @return Collection<int, array{chunk: \Awais\RagChat\Models\RagChunk, score: float}>
     */
    protected function searchOnce(string $query, int $topK, float $minScore): Collection
    {
        $queryVector = $this->embedder->embedQuery($query);

        return $this->store->search($queryVector, $topK, $minScore);
    }
}
