<?php

namespace Awais\RagChat\Rag\Rerankers;

use Awais\RagChat\Contracts\Reranker;
use Awais\RagChat\Rag\Terms;
use Illuminate\Support\Collection;

/**
 * Re-scores candidates by exact term overlap with the query, then re-sorts.
 *
 * No external API — works with any embedding provider and local models. Chunks
 * sharing query terms get a small additive boost capped at 0.3, so a keyword
 * match can promote an exact-token chunk ahead of a merely-similar one without
 * drowning out high-confidence vector hits.
 */
class LexicalReranker implements Reranker
{
    public function rerank(string $query, Collection $matches): Collection
    {
        $terms = Terms::extract($query);

        if ($matches->isEmpty() || $terms === []) {
            return $matches;
        }

        return $matches
            ->map(function (array $match) use ($terms) {
                $content = mb_strtolower((string) $match['chunk']->content);
                $hits = collect($terms)
                    ->filter(fn (string $term) => str_contains($content, $term))
                    ->count();

                if ($hits > 0) {
                    $match['score'] = (float) $match['score'] + min(0.3, 0.05 * $hits);
                }

                return $match;
            })
            ->sortByDesc('score')
            ->values();
    }
}
