<?php

namespace Awais\RagChat\Support;

use Awais\RagChat\Citations\CitationCollection;
use Illuminate\Support\Collection;

/**
 * Answer confidence / groundedness evaluation (Tier 4).
 *
 * Maps retrieval evidence + validated citations to a four-level verdict used
 * for observability and to prefer the safe not-found answer over guessing:
 *
 *  - unsupported : no usable evidence (empty matches) — do not fabricate
 *  - low         : weak best-match score and/or no citations
 *  - medium      : usable evidence, below the high threshold
 *  - high        : strong best-match score with supporting citations
 */
class Groundedness
{
    /**
     * @param  Collection<int, array{chunk: mixed, score: float}>  $matches
     */
    public static function evaluate(Collection $matches, CitationCollection $citations): string
    {
        if ($matches->isEmpty()) {
            return 'unsupported';
        }

        $best = (float) $matches->first()['score'];
        $high = (float) config('rag-chat.confidence.high_score', 0.65);
        $medium = (float) config('rag-chat.confidence.medium_score', 0.45);

        if ($best >= $high && ! $citations->isEmpty()) {
            return 'high';
        }

        if ($best >= $medium || ! $citations->isEmpty()) {
            return 'medium';
        }

        return 'low';
    }
}
