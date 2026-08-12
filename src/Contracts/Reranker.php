<?php

namespace Awais\RagChat\Contracts;

use Illuminate\Support\Collection;

/**
 * A reranker re-scores and re-orders the candidate chunks produced by initial
 * retrieval, before the final set is sent to the LLM.
 *
 * Reranking is optional and provider-agnostic: the package ships a NoopReranker
 * (disabled) and a LexicalReranker (term overlap, no external API). Custom
 * implementations (cross-encoder APIs, etc.) plug in through config
 * (rag-chat.retrieval.reranker) and this contract.
 */
interface Reranker
{
    /**
     * Re-score the candidate matches for a query.
     *
     * Must return the same shape it received:
     *
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    public function rerank(string $query, Collection $matches): Collection;
}
