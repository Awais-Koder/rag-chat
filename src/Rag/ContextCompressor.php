<?php

namespace Awais\RagChat\Rag;

use Illuminate\Support\Collection;

/**
 * Optional post-retrieval context compression (Tier 4, off by default).
 *
 * Sits between retrieval/expansion and the LLM context: drops chunks below a
 * relevance floor and caps the total context size in characters. Chunks are
 * consumed in rank order, so the strongest evidence always wins the budget.
 *
 * Citation metadata is untouched — compression only decides which chunks go
 * into the context, it never rewrites a chunk's stored identity, so the
 * registry/citation mapping still resolves to the real source.
 */
class ContextCompressor
{
    /**
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     * @return Collection<int, array{chunk: mixed, score: float}>
     */
    public function compress(Collection $matches): Collection
    {
        if (! (bool) config('rag-chat.compression.enabled', false) || $matches->isEmpty()) {
            return $matches;
        }

        $floor = (float) config('rag-chat.compression.min_relevance', 0.0);
        $budget = (int) config('rag-chat.compression.max_context_chars', 12000);

        $selected = [];
        $used = 0;

        foreach ($matches as $match) {
            if ((float) $match['score'] < $floor) {
                continue;
            }

            $length = mb_strlen((string) $match['chunk']->content);

            if ($used + $length > $budget && $selected !== []) {
                break;
            }

            $selected[] = $match;
            $used += $length;
        }

        return collect($selected);
    }
}
