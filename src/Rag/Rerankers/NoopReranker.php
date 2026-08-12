<?php

namespace Awais\RagChat\Rag\Rerankers;

use Awais\RagChat\Contracts\Reranker;
use Illuminate\Support\Collection;

/**
 * Returns candidates unchanged. The default — reranking is opt-in.
 */
class NoopReranker implements Reranker
{
    public function rerank(string $query, Collection $matches): Collection
    {
        return $matches;
    }
}
