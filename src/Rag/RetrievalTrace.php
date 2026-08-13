<?php

namespace Awais\RagChat\Rag;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;

/**
 * Debug representation of one retrieval pass: which queries ran, what each
 * query returned, and the final (possibly reranked) matches with attribution.
 *
 * Internal/debug only — never returned to end users. Consume via
 * RagChat::trace() / Retriever::trace().
 */
class RetrievalTrace implements Arrayable
{
    /**
     * @param  list<string>  $generatedQueries
     * @param  array<string, list<array{chunk_id: int, score: float}>>  $perQuery
     * @param  list<array{chunk_id: int, score: float, query: string}>  $matches
     * @param  Collection<int, array{chunk: mixed, score: float}>  $retrieved  raw matches used by the pipeline
     */
    public function __construct(
        public readonly string $query,
        public readonly array $generatedQueries,
        public readonly array $perQuery,
        public readonly array $matches,
        public readonly bool $reranked,
        public readonly Collection $retrieved,
    ) {}

    /**
     * @return array{
     *     query: string,
     *     generated_queries: list<string>,
     *     per_query: array<string, list<array{chunk_id: int, score: float}>>,
     *     matches: list<array{chunk_id: int, score: float, query: string}>,
     *     reranked: bool,
     * }
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'generated_queries' => $this->generatedQueries,
            'per_query' => $this->perQuery,
            'matches' => $this->matches,
            'reranked' => $this->reranked,
        ];
    }
}
